<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Person;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TablerUiFoundationTest extends WebTestCase
{
    public function testLoginUsesTablerAuthPrimitivesWithoutChangingSecurityFields(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.page-center .card');
        self::assertSelectorExists('input.form-control[name="_username"]');
        self::assertSelectorExists('input.form-control[name="_password"]');
        self::assertSelectorExists('input[type="hidden"][name="_csrf_token"]');
        self::assertSelectorExists('button.btn.btn-primary[type="submit"]');
    }

    public function testResidentUsesResponsiveVerticalShellWithoutAdminNavigation(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('aside.navbar.navbar-vertical');
        self::assertSelectorExists('button.navbar-toggler[data-bs-target="#sidebar-menu"]');
        self::assertSelectorExists('.page-wrapper');
        self::assertSelectorExists('a[href="/community"]');
        self::assertSelectorExists('a[href="/maintenance"]');
        self::assertSelectorNotExists('a[href="/management/setup"]');
        self::assertSelectorNotExists('a[href="/management/audit"]');
    }

    public function testAdministratorKeepsSystemNavigationInTablerSidebar(): void
    {
        $client = $this->createAuthenticatedClient(['ROLE_ADMIN']);
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('aside.navbar.navbar-vertical');
        self::assertSelectorExists('a[href="/management/setup"]');
        self::assertSelectorExists('a[href="/management/audit"]');
    }

    /** @param list<string> $roles */
    private function createAuthenticatedClient(array $roles = []): KernelBrowser
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $person = new Person('Тест', 'Потребител', email: 'ui-shell@example.com');
        $user = new User($person, 'ui-shell@example.com', 'hash');
        $user->setRoles($roles);

        $entityManager->persist($person);
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);

        return $client;
    }
}
