<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Document;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Security\DocumentAccessPolicy;
use App\Service\DocumentService;
use App\Service\DocumentStorage;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

final class DocumentServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private string $directory;
    private User $manager;
    private User $admin;
    private User $resident;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->manager = $this->persistUser('manager@example.com', ['ROLE_MANAGER']);
        $this->admin = $this->persistUser('admin@example.com', ['ROLE_ADMIN']);
        $this->resident = $this->persistUser('resident@example.com');
        $this->entityManager->flush();

        $this->directory = sys_get_temp_dir().'/vhod-document-service-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->isOpen()) {
            $this->entityManager->close();
        }
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function testManagerUploadPersistsMetadataAndPrivateFile(): void
    {
        $document = $this->service()->upload(
            $this->manager,
            DocumentCategory::HOUSE_RULES,
            DocumentAccessLevel::RESIDENTS,
            '  Правилник  ',
            '  Актуална версия  ',
            $this->pdfUpload('правилник.pdf'),
            new DateTimeImmutable('2026-09-09 10:00:00 Europe/Sofia'),
        );

        self::assertNotNull($document->getId());
        self::assertSame('Правилник', $document->getTitle());
        self::assertSame('Актуална версия', $document->getDescription());
        self::assertSame('правилник.pdf', $document->getOriginalName());
        self::assertFileExists($this->directory.'/'.$document->getStorageName());
        self::assertCount(1, $this->entityManager->getRepository(Document::class)->findAll());
    }

    public function testAdminCanUploadManagementDocument(): void
    {
        $document = $this->service()->upload(
            $this->admin,
            DocumentCategory::OTHER,
            DocumentAccessLevel::MANAGEMENT,
            'Документ за управление',
            null,
            $this->pdfUpload('management.pdf'),
            new DateTimeImmutable(),
        );

        self::assertSame(DocumentAccessLevel::MANAGEMENT, $document->getAccessLevel());
    }

    public function testResidentCashierAndControllerAreRejectedBeforeFilesystemWork(): void
    {
        foreach ([
            $this->resident,
            $this->persistRoleUser('cashier@example.com', 'ROLE_CASHIER'),
            $this->persistRoleUser('controller@example.com', 'ROLE_CONTROLLER'),
        ] as $actor) {
            try {
                $this->service()->upload(
                    $actor,
                    DocumentCategory::OTHER,
                    DocumentAccessLevel::RESIDENTS,
                    'Документ',
                    null,
                    $this->pdfUpload('document.pdf'),
                    new DateTimeImmutable(),
                );
                self::fail('Non-management user must not upload official documents.');
            } catch (DomainException) {
                self::assertSame([], glob($this->directory.'/*.pdf') ?: []);
            }
        }
    }

    public function testStoredFileIsRemovedWhenPersistenceFails(): void
    {
        $service = $this->service();
        $transientManager = new User(new Person('Нов', 'Управител', email: 'transient@example.com'), 'transient@example.com', 'hash');
        $transientManager->setRoles(['ROLE_MANAGER']);

        try {
            $service->upload(
                $transientManager,
                DocumentCategory::OTHER,
                DocumentAccessLevel::RESIDENTS,
                'Неперсистиран документ',
                null,
                $this->pdfUpload('document.pdf'),
                new DateTimeImmutable(),
            );
            self::fail('Expected persistence failure for transient uploader association.');
        } catch (Throwable) {
            self::assertSame([], glob($this->directory.'/*.pdf') ?: []);
            self::assertCount(0, $this->entityManager->getRepository(Document::class)->findAll());
        }
    }

    private function service(): DocumentService
    {
        self::assertTrue(class_exists(DocumentService::class), 'DocumentService has not been implemented yet.');
        self::assertTrue(class_exists(DocumentStorage::class), 'DocumentStorage has not been implemented yet.');

        return new DocumentService(
            $this->entityManager,
            new DocumentStorage($this->directory),
            new DocumentAccessPolicy(),
        );
    }

    /** @param list<string> $roles */
    private function persistUser(string $email, array $roles = []): User
    {
        $person = new Person('Иван', 'Иванов', email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);
        $this->entityManager->persist($person);
        $this->entityManager->persist($user);

        return $user;
    }

    private function persistRoleUser(string $email, string $role): User
    {
        $user = $this->persistUser($email, [$role]);
        $this->entityManager->flush();

        return $user;
    }

    private function pdfUpload(string $name): UploadedFile
    {
        if (!is_dir($this->directory)) {
            self::assertTrue(mkdir($this->directory, 0700, true));
        }

        $path = $this->directory.'/source-'.bin2hex(random_bytes(6));
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");

        return new UploadedFile($path, $name, null, null, true);
    }
}
