<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Person;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ManagementAccessMatrixTest extends WebTestCase
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

        $this->userIds['resident'] = $this->persistUser($entityManager, 'resident-access@example.com', []);
        $this->userIds['cashier'] = $this->persistUser($entityManager, 'cashier-access@example.com', ['ROLE_CASHIER']);
        $this->userIds['controller'] = $this->persistUser($entityManager, 'controller-access@example.com', ['ROLE_CONTROLLER']);
        $this->userIds['manager'] = $this->persistUser($entityManager, 'manager-access@example.com', ['ROLE_MANAGER']);
        $this->userIds['admin'] = $this->persistUser($entityManager, 'admin-access@example.com', ['ROLE_ADMIN']);
    }

    public function testUnauthenticatedManagementAccessRedirectsToLogin(): void
    {
        $this->client->request('GET', '/management/book');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testManagementRoleMatrix(): void
    {
        /** @var array<string, list<string>> $matrix */
        $matrix = [
            '/management/book' => ['controller', 'manager', 'admin'],
            '/management/finance' => ['cashier', 'controller', 'manager', 'admin'],
            '/management/community/moderation' => ['manager', 'admin'],
            '/management/maintenance' => ['manager', 'admin'],
            '/management/documents' => ['manager', 'admin'],
            '/management/announcements' => ['manager', 'admin'],
            '/management/assemblies' => ['controller', 'manager', 'admin'],
            '/management/compliance' => ['controller', 'manager', 'admin'],
            '/management/audit' => ['admin'],
        ];

        foreach ($matrix as $path => $allowedRoles) {
            foreach (array_keys($this->userIds) as $role) {
                $this->client->loginUser($this->user($role));
                $this->client->request('GET', $path);

                if (in_array($role, $allowedRoles, true)) {
                    self::assertResponseIsSuccessful(sprintf('%s should allow %s.', $path, $role));
                } else {
                    self::assertResponseStatusCodeSame(403, sprintf('%s should deny %s.', $path, $role));
                }
            }
        }
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
        $person = new Person('Access', ucfirst($roles[0] ?? 'resident'), email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);
        $entityManager->persist($person);
        $entityManager->persist($user);
        $entityManager->flush();
        self::assertNotNull($user->getId());

        return $user->getId();
    }
}
