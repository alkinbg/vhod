<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AssemblyAttendance;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\AssemblyQuorumCheck;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\AssemblyAttendanceMode;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyLegalResult;
use App\Enum\AssemblyPrincipalType;
use App\Enum\AssemblyQuorumCheckKind;
use App\Value\AssemblyQuorumRuleSnapshot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GeneralAssemblyWorkbenchControllerTest extends WebTestCase
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

        $this->residentId = $this->persistUser($em, 'resident-workbench@example.com');
        $this->cashierId = $this->persistUser($em, 'cashier-workbench@example.com', ['ROLE_CASHIER']);
        $this->controllerId = $this->persistUser($em, 'controller-workbench@example.com', ['ROLE_CONTROLLER']);
        $this->managerId = $this->persistUser($em, 'manager-workbench@example.com', ['ROLE_MANAGER']);
        $this->adminId = $this->persistUser($em, 'admin-workbench@example.com', ['ROLE_ADMIN']);
        $em->flush();
    }

    public function testWorkbenchRoleMatrixAndCurrentRepresentationWarnings(): void
    {
        [$assembly] = $this->persistConvenedAssemblyWithElectorate();
        self::assertNotNull($assembly->getId());
        $url = '/management/assembly/'.$assembly->getId().'/workbench';

        $this->client->request('GET', $url);
        self::assertResponseRedirects('/login');

        foreach ([$this->residentId, $this->cashierId] as $id) {
            self::ensureKernelShutdown();
            $this->client = self::createClient();
            $this->client->loginUser($this->user($id));
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
        }

        foreach ([$this->controllerId, $this->managerId, $this->adminId] as $id) {
            self::ensureKernelShutdown();
            $this->client = self::createClient();
            $this->client->loginUser($this->user($id));
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', '60.00000000%');
            self::assertSelectorTextContains('body', '1 непредставен');
        }
    }

    public function testQuorumCheckRequiresCsrfAndPersistsImmutableHistory(): void
    {
        [$assembly, $firstEntry, $secondEntry] = $this->persistConvenedAssemblyWithElectorate();
        self::assertNotNull($assembly->getId());
        $id = $assembly->getId();

        $this->client->loginUser($this->user($this->managerId));
        $this->client->request('POST', '/management/assembly/'.$id.'/quorum-check', [
            'kind' => AssemblyQuorumCheckKind::FIRST_CALL->value,
            '_token' => 'invalid',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->entityManager()->getRepository(AssemblyQuorumCheck::class)->findAll());

        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($this->user($this->managerId));
        $crawler = $this->client->request('GET', '/management/assembly/'.$id.'/workbench');
        $form = $crawler->selectButton('assembly_quorum_first_'.$id)->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/management/assembly/'.$id.'/workbench');

        $em = $this->entityManager();
        $checks = $em->getRepository(AssemblyQuorumCheck::class)->findBy(['assembly' => $em->find(GeneralAssembly::class, $id)], ['checkedAt' => 'ASC', 'id' => 'ASC']);
        self::assertCount(1, $checks);
        self::assertSame('60.00000000', $checks[0]->getRepresentedIdealPartsPercent());
        self::assertSame('75.00000000', $checks[0]->getRequiredIdealPartsPercent());
        self::assertSame(AssemblyLegalResult::INVALID, $checks[0]->getResult());

        $reloadedAssembly = $em->find(GeneralAssembly::class, $id);
        $reloadedSecond = $em->find(AssemblyElectorateEntry::class, $secondEntry->getId());
        self::assertInstanceOf(GeneralAssembly::class, $reloadedAssembly);
        self::assertInstanceOf(AssemblyElectorateEntry::class, $reloadedSecond);
        $attendance = AssemblyAttendance::register(
            $reloadedAssembly,
            $reloadedSecond,
            AssemblyAttendanceMode::IN_PERSON,
            $this->user($this->managerId),
            new DateTimeImmutable('2026-09-20T15:10:00Z'),
        );
        $em->persist($attendance);
        $em->flush();

        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($this->user($this->managerId));
        $crawler = $this->client->request('GET', '/management/assembly/'.$id.'/workbench');
        $form = $crawler->selectButton('assembly_quorum_first_'.$id)->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/management/assembly/'.$id.'/workbench');

        $em = $this->entityManager();
        $assembly = $em->find(GeneralAssembly::class, $id);
        self::assertInstanceOf(GeneralAssembly::class, $assembly);
        $checks = $em->getRepository(AssemblyQuorumCheck::class)->findBy(['assembly' => $assembly], ['checkedAt' => 'ASC', 'id' => 'ASC']);
        self::assertCount(2, $checks);
        self::assertSame('60.00000000', $checks[0]->getRepresentedIdealPartsPercent());
        self::assertSame('100.00000000', $checks[1]->getRepresentedIdealPartsPercent());
    }

    public function testReviewRequiredIsRenderedAsWarningAndLatestCheckIsNewest(): void
    {
        [$assembly] = $this->persistConvenedAssemblyWithElectorate(reviewRequired: true);
        self::assertNotNull($assembly->getId());
        $id = $assembly->getId();

        $this->client->loginUser($this->user($this->controllerId));
        $crawler = $this->client->request('GET', '/management/assembly/'.$id.'/workbench');
        $form = $crawler->selectButton('assembly_quorum_delayed_'.$id)->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/management/assembly/'.$id.'/workbench');

        $crawler = $this->client->request('GET', '/management/assembly/'.$id.'/workbench');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.assembly-quorum-warning', 'изисква преглед');
        self::assertSelectorNotExists('.assembly-quorum-success');

        $assembly = $this->entityManager()->find(GeneralAssembly::class, $id);
        self::assertInstanceOf(GeneralAssembly::class, $assembly);
        $latest = $this->entityManager()->getRepository(AssemblyQuorumCheck::class)->findOneBy(['assembly' => $assembly], ['checkedAt' => 'DESC', 'id' => 'DESC']);
        self::assertInstanceOf(AssemblyQuorumCheck::class, $latest);
        self::assertSame(AssemblyLegalResult::REVIEW_REQUIRED, $latest->getResult());
        self::assertSame(AssemblyQuorumCheckKind::DELAYED_CALL, $latest->getKind());
    }

    /** @return array{GeneralAssembly, AssemblyElectorateEntry, AssemblyElectorateEntry} */
    private function persistConvenedAssemblyWithElectorate(bool $reviewRequired = false): array
    {
        $em = $this->entityManager();
        $manager = $this->user($this->managerId);
        $firstPerson = new Person('Анна', 'Първа');
        $secondPerson = new Person('Борис', 'Втори');
        $firstUnit = new Unit('Ап. 1', idealParts: '60.0000');
        $secondUnit = new Unit('Ап. 2', idealParts: '40.0000');
        $assembly = GeneralAssembly::draft(
            'Работно общо събрание',
            new DateTimeImmutable('2026-09-20T15:00:00Z'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-09'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Управител',
            $manager,
            $manager,
            new DateTimeImmutable('2026-09-09T08:00:00Z'),
        );
        $assembly->snapshotQuorumRule(new AssemblyQuorumRuleSnapshot(
            'zues-2026-default',
            '51',
            '26',
            '51',
            '75',
            'ЗУЕС — приложим кворум',
            'effective-through-2026-09-09',
            $reviewRequired,
        ));

        $firstEntry = AssemblyElectorateEntry::snapshot(
            $assembly,
            $firstUnit,
            null,
            'Ап. 1',
            AssemblyPrincipalType::PERSON,
            $firstPerson,
            'Анна Първа',
            null,
            'owner',
            '100',
            '60',
            '60',
            true,
            null,
            new DateTimeImmutable('2026-09-09T08:05:00Z'),
        );
        $secondEntry = AssemblyElectorateEntry::snapshot(
            $assembly,
            $secondUnit,
            null,
            'Ап. 2',
            AssemblyPrincipalType::PERSON,
            $secondPerson,
            'Борис Втори',
            null,
            'owner',
            '100',
            '40',
            '40',
            true,
            null,
            new DateTimeImmutable('2026-09-09T08:05:00Z'),
        );

        foreach ([$firstPerson, $secondPerson, $firstUnit, $secondUnit, $assembly, $firstEntry, $secondEntry] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $assembly->convene($manager, new DateTimeImmutable('2026-09-19T12:00:00Z'));
        $attendance = AssemblyAttendance::register(
            $assembly,
            $firstEntry,
            AssemblyAttendanceMode::IN_PERSON,
            $manager,
            new DateTimeImmutable('2026-09-20T15:00:00Z'),
        );
        $em->persist($attendance);
        $em->flush();

        return [$assembly, $firstEntry, $secondEntry];
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
}
