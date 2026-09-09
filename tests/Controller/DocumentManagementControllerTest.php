<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Document;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DocumentManagementControllerTest extends WebTestCase
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

        $this->residentId = $this->persistUser($em, 'resident-manage-docs@example.com');
        $this->cashierId = $this->persistUser($em, 'cashier-manage-docs@example.com', ['ROLE_CASHIER']);
        $this->controllerId = $this->persistUser($em, 'controller-manage-docs@example.com', ['ROLE_CONTROLLER']);
        $this->managerId = $this->persistUser($em, 'manager-manage-docs@example.com', ['ROLE_MANAGER']);
        $this->adminId = $this->persistUser($em, 'admin-manage-docs@example.com', ['ROLE_ADMIN']);
        $em->flush();
        $this->clearStorage();
    }

    protected function tearDown(): void
    {
        $this->clearStorage();
        parent::tearDown();
    }

    public function testOnlyManagerAndAdminCanOpenManagementPage(): void
    {
        foreach ([$this->residentId, $this->cashierId, $this->controllerId] as $id) {
            self::ensureKernelShutdown();
            $this->client = self::createClient();
            $this->client->loginUser($this->user($id));
            $this->client->request('GET', '/management/documents');
            self::assertResponseStatusCodeSame(403);
        }

        foreach ([$this->managerId, $this->adminId] as $id) {
            self::ensureKernelShutdown();
            $this->client = self::createClient();
            $this->client->loginUser($this->user($id));
            $this->client->request('GET', '/management/documents');
            self::assertResponseIsSuccessful();
        }
    }

    public function testManagerCanUploadPrivateDocument(): void
    {
        $this->client->loginUser($this->user($this->managerId));
        $crawler = $this->client->request('GET', '/management/document/new');
        self::assertResponseIsSuccessful();

        $path = $this->pdfFixture();
        $form = $crawler->selectButton('document_submit')->form([
            'category' => DocumentCategory::HOUSE_RULES->value,
            'access_level' => DocumentAccessLevel::RESIDENTS->value,
            'title' => 'Правилник за вътрешния ред',
            'description' => 'Официален документ.',
        ]);
        $form['document_file']->upload($path);
        $this->client->submit($form);

        self::assertResponseRedirects('/management/documents');
        $documents = $this->entityManager()->getRepository(Document::class)->findAll();
        self::assertCount(1, $documents);
        self::assertSame('Правилник за вътрешния ред', $documents[0]->getTitle());
        self::assertSame(DocumentAccessLevel::RESIDENTS, $documents[0]->getAccessLevel());
        self::assertFileExists($this->storageDirectory().'/'.$documents[0]->getStorageName());
        @unlink($path);
    }

    public function testInvalidCsrfLeavesNoDatabaseOrFileState(): void
    {
        $this->client->loginUser($this->user($this->managerId));
        $path = $this->pdfFixture();
        $this->client->request('POST', '/management/document/new', [
            'category' => DocumentCategory::HOUSE_RULES->value,
            'access_level' => DocumentAccessLevel::RESIDENTS->value,
            'title' => 'Blocked',
            '_token' => 'invalid',
        ], [
            'document_file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'blocked.pdf', 'application/pdf', null, true),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->entityManager()->getRepository(Document::class)->findAll());
        self::assertSame([], array_values(array_filter(glob($this->storageDirectory().'/*') ?: [], 'is_file')));
        @unlink($path);
    }

    public function testInvalidEnumReturnsUnprocessableAndLeavesNoState(): void
    {
        $this->client->loginUser($this->user($this->managerId));
        $crawler = $this->client->request('GET', '/management/document/new');
        $path = $this->pdfFixture();
        $form = $crawler->selectButton('document_submit')->form([
            'category' => 'invalid-category',
            'access_level' => DocumentAccessLevel::RESIDENTS->value,
            'title' => 'Invalid',
        ]);
        $form['document_file']->upload($path);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->entityManager()->getRepository(Document::class)->findAll());
        self::assertSame([], array_values(array_filter(glob($this->storageDirectory().'/*') ?: [], 'is_file')));
        @unlink($path);
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

    private function pdfFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vhod-doc-');
        self::assertIsString($path);
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        return $path;
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
