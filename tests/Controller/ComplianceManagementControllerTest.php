<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ComplianceCompletion;
use App\Entity\CondominiumProfile;
use App\Entity\ManagementMandate;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\ComplianceCompletionType;
use App\Enum\ManagementMandateKind;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ComplianceManagementControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @var array<string, int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $entityManager = $this->entityManager();
        $tool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->userIds['resident'] = $this->persistUser($entityManager, 'resident-compliance@example.com', []);
        $this->userIds['cashier'] = $this->persistUser($entityManager, 'cashier-compliance@example.com', ['ROLE_CASHIER']);
        $this->userIds['manager'] = $this->persistUser($entityManager, 'manager-compliance@example.com', ['ROLE_MANAGER']);
        $this->userIds['controller'] = $this->persistUser($entityManager, 'controller-compliance@example.com', ['ROLE_CONTROLLER']);
        $this->userIds['admin'] = $this->persistUser($entityManager, 'admin-compliance@example.com', ['ROLE_ADMIN']);
        $entityManager->flush();
    }

    public function testCompliancePageRoleMatrixAndGetDoesNotCreateProfile(): void
    {
        $this->client->request('GET', '/management/compliance');
        self::assertResponseRedirects('http://localhost/login');

        foreach (['resident', 'cashier'] as $role) {
            $this->client->loginUser($this->user($role));
            $this->client->request('GET', '/management/compliance');
            self::assertResponseStatusCodeSame(403, $role);
        }

        foreach (['manager', 'controller', 'admin'] as $role) {
            $this->client->loginUser($this->user($role));
            $this->client->request('GET', '/management/compliance');
            self::assertResponseIsSuccessful($role);
        }

        self::assertCount(0, $this->entityManager()->getRepository(CondominiumProfile::class)->findAll());
    }

    public function testManagerCanCreateAndUpdateRegistryMetadataWithoutReplacingExternalIdentifier(): void
    {
        $this->client->loginUser($this->user('manager'));
        $crawler = $this->client->request('GET', '/management/compliance');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('registry_submit')->form([
            'registry_identifier' => '  EISES-RUSE-000123  ',
            'registry_parcel_number' => '  18.123.456  ',
            'registry_registered_at' => '2026-09-01',
        ]));
        self::assertResponseRedirects('/management/compliance');

        $entityManager = $this->entityManager();
        /** @var list<CondominiumProfile> $profiles */
        $profiles = $entityManager->getRepository(CondominiumProfile::class)->findAll();
        self::assertCount(1, $profiles);
        self::assertSame('EISES-RUSE-000123', $profiles[0]->getRegistryIdentifier());
        self::assertSame('18.123.456', $profiles[0]->getRegistryParcelNumber());
        self::assertSame($this->userIds['manager'], $profiles[0]->getCreatedBy()->getId());

        $crawler = $this->client->request('GET', '/management/compliance');
        self::assertSelectorExists('input[name="registry_identifier"][readonly]');
        $this->client->submit($crawler->selectButton('registry_submit')->form([
            'registry_identifier' => 'EISES-RUSE-000123',
            'registry_parcel_number' => '',
            'registry_registered_at' => '2026-09-02',
        ]));
        self::assertResponseRedirects('/management/compliance');

        $entityManager = $this->entityManager();
        /** @var list<CondominiumProfile> $profiles */
        $profiles = $entityManager->getRepository(CondominiumProfile::class)->findAll();
        self::assertCount(1, $profiles);
        self::assertSame('EISES-RUSE-000123', $profiles[0]->getRegistryIdentifier());
        self::assertNull($profiles[0]->getRegistryParcelNumber());
        self::assertSame('2026-09-02', $profiles[0]->getRegistryRegisteredAt()?->format('Y-m-d'));

        $crawler = $this->client->request('GET', '/management/compliance');
        $form = $crawler->selectButton('registry_submit')->form();
        $this->client->request('POST', '/management/compliance/registry', [
            '_token' => $form->get('_token')->getValue(),
            'registry_identifier' => 'EISES-RUSE-000999',
            'registry_parcel_number' => 'FORBIDDEN-MUTATION',
            'registry_registered_at' => '2026-09-03',
        ]);
        self::assertResponseStatusCodeSame(422);

        $entityManager->clear();
        /** @var list<CondominiumProfile> $profiles */
        $profiles = $entityManager->getRepository(CondominiumProfile::class)->findAll();
        self::assertCount(1, $profiles);
        self::assertSame('EISES-RUSE-000123', $profiles[0]->getRegistryIdentifier());
        self::assertNull($profiles[0]->getRegistryParcelNumber());
        self::assertSame('2026-09-02', $profiles[0]->getRegistryRegisteredAt()?->format('Y-m-d'));
    }

    public function testManagerCanAppendMandateAndMonthlyCompletion(): void
    {
        $this->client->loginUser($this->user('manager'));
        $crawler = $this->client->request('GET', '/management/compliance');

        $this->client->submit($crawler->selectButton('mandate_submit')->form([
            'kind' => ManagementMandateKind::MANAGER->value,
            'holder_label' => 'Мария Петрова',
            'starts_at' => '2026-09-01',
            'ends_at' => '2028-08-31',
            'note' => 'Решение на общото събрание',
        ]));
        self::assertResponseRedirects('/management/compliance');

        $crawler = $this->client->request('GET', '/management/compliance');
        $this->client->submit($crawler->selectButton('completion_monthly_submit')->form([
            'type' => ComplianceCompletionType::MONTHLY_REPORT->value,
            'period_key' => '2026-08',
            'completed_at' => '2026-09-05',
            'note' => 'Публикуван отчет',
        ]));
        self::assertResponseRedirects('/management/compliance');

        $entityManager = $this->entityManager();
        /** @var list<ManagementMandate> $mandates */
        $mandates = $entityManager->getRepository(ManagementMandate::class)->findAll();
        /** @var list<ComplianceCompletion> $completions */
        $completions = $entityManager->getRepository(ComplianceCompletion::class)->findAll();
        self::assertCount(1, $mandates);
        self::assertCount(1, $completions);
        self::assertSame('Мария Петрова', $mandates[0]->getHolderLabel());
        self::assertSame('2026-08', $completions[0]->getPeriodKey());
        self::assertSame($this->userIds['manager'], $mandates[0]->getRecordedBy()->getId());
        self::assertSame($this->userIds['manager'], $completions[0]->getRecordedBy()->getId());
    }

    public function testControllerCanRecordAnnualAuditButCannotMutateRegistryMandateOrMonthlyReport(): void
    {
        $this->client->loginUser($this->user('controller'));
        $crawler = $this->client->request('GET', '/management/compliance');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('button[name="registry_submit"]');
        self::assertSelectorNotExists('button[name="mandate_submit"]');
        self::assertSelectorNotExists('button[name="completion_monthly_submit"]');
        self::assertSelectorExists('button[name="completion_annual_submit"]');

        $this->client->request('POST', '/management/compliance/registry', [
            '_token' => 'ignored-before-capability',
            'registry_identifier' => 'FORBIDDEN',
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/management/compliance/mandate', [
            '_token' => 'ignored-before-capability',
            'kind' => ManagementMandateKind::MANAGER->value,
            'holder_label' => 'Forbidden',
            'starts_at' => '2026-01-01',
            'ends_at' => '2027-01-01',
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/management/compliance/completion', [
            '_token' => 'ignored-before-capability',
            'type' => ComplianceCompletionType::MONTHLY_REPORT->value,
            'period_key' => '2026-08',
            'completed_at' => '2026-09-05',
        ]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', '/management/compliance');
        $this->client->submit($crawler->selectButton('completion_annual_submit')->form([
            'type' => ComplianceCompletionType::ANNUAL_CASH_AUDIT->value,
            'period_key' => '2026',
            'completed_at' => '2026-06-30',
            'note' => 'Проверката е отразена в протокол',
        ]));
        self::assertResponseRedirects('/management/compliance');

        self::assertCount(0, $this->entityManager()->getRepository(CondominiumProfile::class)->findAll());
        self::assertCount(0, $this->entityManager()->getRepository(ManagementMandate::class)->findAll());
        /** @var list<ComplianceCompletion> $completions */
        $completions = $this->entityManager()->getRepository(ComplianceCompletion::class)->findAll();
        self::assertCount(1, $completions);
        self::assertSame(ComplianceCompletionType::ANNUAL_CASH_AUDIT, $completions[0]->getType());
    }

    public function testInvalidCsrfBlocksEveryComplianceMutationFamilyWithoutPersistingAnything(): void
    {
        $this->client->loginUser($this->user('manager'));

        foreach ([
            ['/management/compliance/registry', ['_token' => 'invalid', 'registry_identifier' => 'X']],
            ['/management/compliance/mandate', ['_token' => 'invalid', 'kind' => ManagementMandateKind::MANAGER->value]],
            ['/management/compliance/completion', ['_token' => 'invalid', 'type' => ComplianceCompletionType::MONTHLY_REPORT->value]],
        ] as [$uri, $parameters]) {
            $this->client->request('POST', $uri, $parameters);
            self::assertResponseStatusCodeSame(403, $uri);
        }

        self::assertCount(0, $this->entityManager()->getRepository(CondominiumProfile::class)->findAll());
        self::assertCount(0, $this->entityManager()->getRepository(ManagementMandate::class)->findAll());
        self::assertCount(0, $this->entityManager()->getRepository(ComplianceCompletion::class)->findAll());
    }

    public function testDuplicateCompletionIsRejectedWithoutCreatingSecondFact(): void
    {
        $manager = $this->user('manager');
        $existing = ComplianceCompletion::record(
            ComplianceCompletionType::MONTHLY_REPORT,
            '2026-08',
            new DateTimeImmutable('2026-09-05'),
            $manager,
            new DateTimeImmutable('2026-09-05T08:00:00Z'),
        );
        $entityManager = $this->entityManager();
        $entityManager->persist($existing);
        $entityManager->flush();

        $this->client->loginUser($manager);
        $crawler = $this->client->request('GET', '/management/compliance');
        $this->client->submit($crawler->selectButton('completion_monthly_submit')->form([
            'type' => ComplianceCompletionType::MONTHLY_REPORT->value,
            'period_key' => '2026-08',
            'completed_at' => '2026-09-06',
            'note' => 'Дубликат',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->entityManager()->getRepository(ComplianceCompletion::class)->findAll());
    }

    public function testPageRendersReminderFromPersistedFactsAndNavigationIsCapabilityAware(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Sofia'));
        $manager = $this->user('manager');
        $mandate = ManagementMandate::record(
            ManagementMandateKind::MANAGER,
            'Изтичащ мандат',
            $today->modify('-1 year'),
            $today->modify('+15 days'),
            $manager,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        $entityManager = $this->entityManager();
        $entityManager->persist($mandate);
        $entityManager->flush();

        $this->client->loginUser($manager);
        $this->client->request('GET', '/management/compliance');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Изтичащ мандат');
        self::assertSelectorTextContains('body', 'Мандатът на управлението изтича скоро');
        self::assertSelectorExists('a[href="/management/compliance"]');

        $this->client->loginUser($this->user('resident'));
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/management/compliance"]');
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function user(string $role): User
    {
        $user = $this->entityManager()->find(User::class, $this->userIds[$role]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /** @param list<string> $roles */
    private function persistUser(EntityManagerInterface $entityManager, string $email, array $roles): int
    {
        $person = new Person('Тест', ucfirst(str_replace('-compliance@example.com', '', $email)), email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);
        $entityManager->persist($person);
        $entityManager->persist($user);
        $entityManager->flush();
        self::assertNotNull($user->getId());

        return $user->getId();
    }
}
