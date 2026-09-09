<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AssemblyQuorumCheck;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyLegalResult;
use App\Enum\AssemblyQuorumCheckKind;
use App\Enum\GeneralAssemblyStatus;
use App\Value\AssemblyQuorumCalculation;
use App\Value\AssemblyQuorumRuleSnapshot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GeneralAssemblyLifecycleControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $managerId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = $this->entityManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $person = new Person('Мария', 'Управител', email: 'manager-lifecycle@example.com');
        $manager = new User($person, 'manager-lifecycle@example.com', 'hash');
        $manager->setRoles(['ROLE_MANAGER']);
        $em->persist($person);
        $em->persist($manager);
        $em->flush();
        self::assertNotNull($manager->getId());
        $this->managerId = $manager->getId();
    }

    public function testConvenedMeetingCanStartEvenWhenLatestQuorumCheckIsInvalidAndThenClose(): void
    {
        $em = $this->entityManager();
        $manager = $this->manager();
        $assembly = $this->convenedAssembly($manager);
        $invalid = AssemblyQuorumCheck::record(
            $assembly,
            AssemblyQuorumCheckKind::FIRST_CALL,
            new DateTimeImmutable('2026-09-09T14:00:00Z'),
            new AssemblyQuorumCalculation(
                '40.00000000',
                '51.00000000',
                'zues-2025-default:first-call',
                AssemblyLegalResult::INVALID,
                'Недостатъчен кворум.',
            ),
            $manager,
        );
        $em->persist($invalid);
        $em->flush();
        self::assertNotNull($assembly->getId());
        $id = $assembly->getId();

        $this->client->loginUser($manager);
        $crawler = $this->client->request('GET', '/management/assembly/'.$id.'/workbench');
        self::assertResponseIsSuccessful();
        $startForm = $crawler->selectButton('assembly_start_'.$id)->form();
        $this->client->submit($startForm);
        self::assertResponseRedirects('/management/assembly/'.$id.'/workbench');

        $em = $this->entityManager();
        $reloaded = $em->find(GeneralAssembly::class, $id);
        self::assertInstanceOf(GeneralAssembly::class, $reloaded);
        self::assertSame(GeneralAssemblyStatus::IN_PROGRESS, $reloaded->getStatus());

        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($this->manager());
        $crawler = $this->client->request('GET', '/management/assembly/'.$id.'/workbench');
        $closeForm = $crawler->selectButton('assembly_close_'.$id)->form();
        $this->client->submit($closeForm);
        self::assertResponseRedirects('/management/assembly/'.$id.'/workbench');

        $reloaded = $this->entityManager()->find(GeneralAssembly::class, $id);
        self::assertInstanceOf(GeneralAssembly::class, $reloaded);
        self::assertSame(GeneralAssemblyStatus::CLOSED, $reloaded->getStatus());
    }

    public function testStartAndCloseRequireValidCsrf(): void
    {
        $assembly = $this->convenedAssembly($this->manager());
        $this->entityManager()->flush();
        self::assertNotNull($assembly->getId());
        $id = $assembly->getId();

        $this->client->loginUser($this->manager());
        $this->client->request('POST', '/management/assembly/'.$id.'/start', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $reloaded = $this->entityManager()->find(GeneralAssembly::class, $id);
        self::assertInstanceOf(GeneralAssembly::class, $reloaded);
        self::assertSame(GeneralAssemblyStatus::CONVENED, $reloaded->getStatus());
    }

    private function convenedAssembly(User $manager): GeneralAssembly
    {
        $assembly = GeneralAssembly::draft(
            'Общо събрание',
            new DateTimeImmutable('2026-09-09T14:00:00Z'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-09'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Мария Управител',
            $manager,
            $manager,
            new DateTimeImmutable('2026-09-09T08:00:00Z'),
        );
        $assembly->snapshotQuorumRule(new AssemblyQuorumRuleSnapshot(
            'zues-2025-default',
            '51',
            '26',
            '51',
            '75',
            'ЗУЕС — приложим кворум',
            'effective-through-2026-09-09',
            false,
        ));
        $this->entityManager()->persist($assembly);
        $this->entityManager()->flush();
        $assembly->convene($manager, new DateTimeImmutable('2026-09-09T12:00:00Z'));
        $this->entityManager()->flush();

        return $assembly;
    }

    private function manager(): User
    {
        $manager = $this->entityManager()->find(User::class, $this->managerId);
        self::assertInstanceOf(User::class, $manager);

        return $manager;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
