<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Person;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GeneralAssemblyNavigationTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $residentId;
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

        $resident = $this->persistUser($em, 'resident-assembly-nav@example.com');
        $controller = $this->persistUser($em, 'controller-assembly-nav@example.com', ['ROLE_CONTROLLER']);
        $manager = $this->persistUser($em, 'manager-assembly-nav@example.com', ['ROLE_MANAGER']);
        $admin = $this->persistUser($em, 'admin-assembly-nav@example.com', ['ROLE_ADMIN']);
        $em->flush();

        self::assertNotNull($resident->getId());
        self::assertNotNull($controller->getId());
        self::assertNotNull($manager->getId());
        self::assertNotNull($admin->getId());
        $this->residentId = $resident->getId();
        $this->controllerId = $controller->getId();
        $this->managerId = $manager->getId();
        $this->adminId = $admin->getId();
    }

    public function testResidentNavigationSeparatesOfficialAssembliesFromCommunity(): void
    {
        $this->client->loginUser($this->user($this->residentId));
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('nav a[href="/assemblies"]', 'Общи събрания');
        self::assertSelectorExists('nav a[href="/community"]');
        self::assertSelectorNotExists('nav a[href="/management/assemblies"]');
        self::assertSelectorExists('.general-assembly-dashboard-card a[href="/assemblies"]');
        self::assertSelectorTextContains('.general-assembly-dashboard-card', 'Официално');
    }

    public function testControllerManagerAndAdminSeeManagementAssemblyNavigation(): void
    {
        foreach ([$this->controllerId, $this->managerId, $this->adminId] as $id) {
            self::ensureKernelShutdown();
            $this->client = self::createClient();
            $this->client->loginUser($this->user($id));
            $this->client->request('GET', '/assemblies');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('nav a[href="/assemblies"]');
            self::assertSelectorTextContains('nav a[href="/management/assemblies"]', 'Управление на ОС');
        }
    }

    /** @param list<string> $roles */
    private function persistUser(EntityManagerInterface $em, string $email, array $roles = []): User
    {
        $person = new Person('Иван', 'Иванов', email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);
        $em->persist($person);
        $em->persist($user);

        return $user;
    }

    private function user(int $id): User
    {
        $user = $this->entityManager()->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
