<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Person;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GeneralAssemblySecurityHardeningTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = $this->entityManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testResidentAndCashierCannotReachGeneralAssemblyManagementSurface(): void
    {
        foreach ([[], ['ROLE_CASHIER']] as $roles) {
            self::ensureKernelShutdown();
            $this->client = self::createClient();
            $em = $this->entityManager();
            $user = $this->persistUser($em, 'blocked-'.md5(implode(',', $roles)).'@example.com', $roles);
            $em->flush();

            $this->client->loginUser($user);
            $this->client->request('GET', '/management/assemblies');
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testControllerManagerAndAdminCanReachGeneralAssemblyManagementSurface(): void
    {
        foreach ([['ROLE_CONTROLLER'], ['ROLE_MANAGER'], ['ROLE_ADMIN']] as $roles) {
            self::ensureKernelShutdown();
            $this->client = self::createClient();
            $em = $this->entityManager();
            $user = $this->persistUser($em, 'allowed-'.md5(implode(',', $roles)).'@example.com', $roles);
            $em->flush();

            $this->client->loginUser($user);
            $this->client->request('GET', '/management/assemblies');
            self::assertResponseIsSuccessful();
        }
    }

    public function testEveryKnownGeneralAssemblyManagementPostRejectsInvalidCsrfBeforeMutation(): void
    {
        $em = $this->entityManager();
        $manager = $this->persistUser($em, 'csrf-hardening@example.com', ['ROLE_MANAGER']);
        $em->flush();
        $this->client->loginUser($manager);

        $requests = [
            ['/management/assembly/new', []],
            ['/management/assembly/999999/convene', []],
            ['/management/assembly/999999/posting', []],
            ['/management/assembly/999999/start', []],
            ['/management/assembly/999999/close', []],
            ['/management/assembly/999999/quorum-check', ['kind' => 'first_call']],
            ['/management/assembly/999999/item/999999/open', ['final_resolution_text' => 'test']],
            ['/management/assembly/999999/item/999999/vote/999999', ['choice' => 'for']],
            ['/management/assembly/999999/vote/999999/correct', ['choice' => 'against', 'reason' => 'test']],
            ['/management/assembly/999999/item/999999/resolve', []],
            ['/management/assembly/999999/absentee/open', ['agenda_item_ids' => ['999999']]],
            ['/management/assembly/999999/absentee/999999/declaration', []],
            ['/management/assembly/999999/absentee/999999/close', []],
            ['/management/assembly/999999/minutes/metadata', []],
            ['/management/assembly/999999/minutes/finalize', []],
        ];

        foreach ($requests as [$path, $payload]) {
            $this->client->request('POST', $path, ['_token' => 'definitely-invalid', ...$payload]);
            self::assertContains(
                $this->client->getResponse()->getStatusCode(),
                [403, 404],
                sprintf('Unexpected status for %s', $path),
            );
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param list<string> $roles */
    private function persistUser(EntityManagerInterface $em, string $email, array $roles): User
    {
        $person = new Person('Тест', 'Потребител', email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);
        $em->persist($person);
        $em->persist($user);

        return $user;
    }
}
