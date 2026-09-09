<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Document;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DocumentControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $resident;
    private User $cashier;
    private User $controller;
    private User $manager;
    private User $admin;
    private int $storageCounter = 1;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $entityManager = $this->entityManager();
        $tool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->resident = $this->persistUser($entityManager, 'resident-docs@example.com');
        $this->cashier = $this->persistUser($entityManager, 'cashier-docs@example.com', 'ROLE_CASHIER');
        $this->controller = $this->persistUser($entityManager, 'controller-docs@example.com', 'ROLE_CONTROLLER');
        $this->manager = $this->persistUser($entityManager, 'manager-docs@example.com', 'ROLE_MANAGER');
        $this->admin = $this->persistUser($entityManager, 'admin-docs@example.com', 'ROLE_ADMIN');
        $entityManager->flush();

        $this->clearStorage();
    }

    protected function tearDown(): void
    {
        $this->clearStorage();
        parent::tearDown();
    }

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/documents');

        self::assertResponseRedirects('/login');
    }

    public function testListFiltersDocumentsByRoleAtRepositoryLevel(): void
    {
        $entityManager = $this->entityManager();
        $this->recordDocument($entityManager, DocumentAccessLevel::RESIDENTS, DocumentCategory::HOUSE_RULES, 'Resident document');
        $this->recordDocument($entityManager, DocumentAccessLevel::FINANCE, DocumentCategory::MONTHLY_REPORT, 'Finance document');
        $this->recordDocument($entityManager, DocumentAccessLevel::MANAGEMENT, DocumentCategory::CONTRACT_OFFER, 'Management document');
        $entityManager->flush();

        $expectations = [
            [$this->resident, ['Resident document'], ['Finance document', 'Management document']],
            [$this->cashier, ['Resident document', 'Finance document'], ['Management document']],
            [$this->controller, ['Resident document', 'Finance document'], ['Management document']],
            [$this->manager, ['Resident document', 'Finance document', 'Management document'], []],
            [$this->admin, ['Resident document', 'Finance document', 'Management document'], []],
        ];

        foreach ($expectations as [$user, $visible, $hidden]) {
            self::ensureKernelShutdown();
            $this->client = self::createClient();
            $this->client->loginUser($user);
            $this->client->request('GET', '/documents');

            self::assertResponseIsSuccessful();
            $content = (string) $this->client->getResponse()->getContent();
            foreach ($visible as $title) {
                self::assertStringContainsString($title, $content);
            }
            foreach ($hidden as $title) {
                self::assertStringNotContainsString($title, $content);
            }
        }
    }

    public function testListSupportsCategoryFilter(): void
    {
        $entityManager = $this->entityManager();
        $this->recordDocument($entityManager, DocumentAccessLevel::RESIDENTS, DocumentCategory::HOUSE_RULES, 'House rules');
        $this->recordDocument($entityManager, DocumentAccessLevel::RESIDENTS, DocumentCategory::WARRANTY, 'Lift warranty');
        $entityManager->flush();

        $this->client->loginUser($this->resident);
        $this->client->request('GET', '/documents?category='.DocumentCategory::WARRANTY->value);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Lift warranty');
        self::assertSelectorTextNotContains('body', 'House rules');
    }

    public function testUnknownCategoryReturnsNotFound(): void
    {
        $this->client->loginUser($this->resident);
        $this->client->request('GET', '/documents?category=not-a-real-category');

        self::assertResponseStatusCodeSame(404);
    }

    public function testResidentCanDownloadVisiblePrivateDocumentWithPersistedHeaders(): void
    {
        $entityManager = $this->entityManager();
        $document = $this->recordDocument(
            $entityManager,
            DocumentAccessLevel::RESIDENTS,
            DocumentCategory::HOUSE_RULES,
            'House rules',
            'rules.pdf',
        );
        $entityManager->flush();
        $documentId = $document->getId();
        self::assertNotNull($documentId);
        file_put_contents($this->storageDirectory().'/'.$document->getStorageName(), '%PDF-1.4 test');

        $this->client->loginUser($this->resident);
        $this->client->request('GET', '/document/'.$documentId.'/download');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/pdf');
        self::assertResponseHeaderSame('content-disposition', 'attachment; filename=rules.pdf');
    }

    public function testRestrictedDocumentIdReturnsNotFound(): void
    {
        $entityManager = $this->entityManager();
        $document = $this->recordDocument(
            $entityManager,
            DocumentAccessLevel::FINANCE,
            DocumentCategory::BANK_STATEMENT,
            'Bank statement',
        );
        $entityManager->flush();
        $documentId = $document->getId();
        self::assertNotNull($documentId);

        $this->client->loginUser($this->resident);
        $this->client->request('GET', '/document/'.$documentId.'/download');

        self::assertResponseStatusCodeSame(404);
    }

    public function testMissingPrivateBinaryReturnsNotFound(): void
    {
        $entityManager = $this->entityManager();
        $document = $this->recordDocument(
            $entityManager,
            DocumentAccessLevel::RESIDENTS,
            DocumentCategory::TECHNICAL_DOCUMENTATION,
            'Lift documentation',
        );
        $entityManager->flush();
        $documentId = $document->getId();
        self::assertNotNull($documentId);

        $this->client->loginUser($this->resident);
        $this->client->request('GET', '/document/'.$documentId.'/download');

        self::assertResponseStatusCodeSame(404);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function persistUser(EntityManagerInterface $entityManager, string $email, ?string $role = null): User
    {
        $person = new Person('Иван', 'Иванов', email: $email);
        $user = new User($person, $email, 'hash');
        if (null !== $role) {
            $user->setRoles([$role]);
        }
        $entityManager->persist($person);
        $entityManager->persist($user);

        return $user;
    }

    private function recordDocument(
        EntityManagerInterface $entityManager,
        DocumentAccessLevel $accessLevel,
        DocumentCategory $category,
        string $title,
        string $originalName = 'document.pdf',
    ): Document {
        $storageName = str_pad(dechex($this->storageCounter++), 32, '0', STR_PAD_LEFT).'.pdf';
        $document = Document::record(
            $category,
            $accessLevel,
            $title,
            'Описание на документа.',
            $originalName,
            $storageName,
            'application/pdf',
            13,
            $this->manager,
            new DateTimeImmutable('2026-09-09 07:00:00+00:00'),
        );
        $entityManager->persist($document);

        return $document;
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
