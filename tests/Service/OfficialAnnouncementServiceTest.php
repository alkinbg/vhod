<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AnnouncementReceipt;
use App\Entity\Document;
use App\Entity\OfficialAnnouncement;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Security\DocumentAccessPolicy;
use App\Service\OfficialAnnouncementService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OfficialAnnouncementServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private User $manager;
    private User $admin;
    private User $resident;
    private User $cashier;
    private User $controller;
    private User $inactive;

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
        $this->cashier = $this->persistUser('cashier@example.com', ['ROLE_CASHIER']);
        $this->controller = $this->persistUser('controller@example.com', ['ROLE_CONTROLLER']);
        $this->inactive = $this->persistUser('inactive@example.com', [], false);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->isOpen()) {
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testManagerAndAdminCanCreateAndReviseDraft(): void
    {
        $document = $this->persistDocument(DocumentAccessLevel::RESIDENTS);
        $service = $this->service();

        $announcement = $service->createDraft(
            $this->manager,
            '  Важно съобщение  ',
            '  Първоначален текст.  ',
            new DateTimeImmutable('2026-09-09 10:00:00 Europe/Sofia'),
            [$document],
        );

        self::assertNotNull($announcement->getId());
        self::assertSame('Важно съобщение', $announcement->getTitle());
        self::assertCount(1, $this->entityManager->getRepository(OfficialAnnouncement::class)->findAll());

        $service->revise(
            $this->admin,
            $announcement,
            'Актуализирано съобщение',
            'Актуализиран текст.',
            [],
        );

        self::assertSame('Актуализирано съобщение', $announcement->getTitle());
        self::assertSame('Актуализиран текст.', $announcement->getBody());
        self::assertCount(0, $announcement->getDocuments());
    }

    public function testResidentCashierAndControllerCannotManageOfficialAnnouncements(): void
    {
        $service = $this->service();

        foreach ([$this->resident, $this->cashier, $this->controller] as $actor) {
            try {
                $service->createDraft(
                    $actor,
                    'Забранено',
                    'Този запис не трябва да бъде създаден.',
                    new DateTimeImmutable('2026-09-09 10:00:00 Europe/Sofia'),
                );
                self::fail('Non-management user must not create official announcements.');
            } catch (DomainException) {
                self::assertCount(0, $this->entityManager->getRepository(OfficialAnnouncement::class)->findAll());
            }
        }
    }

    public function testRestrictedDocumentCannotBeLinkedToDraftOrRevision(): void
    {
        $service = $this->service();
        $financeDocument = $this->persistDocument(DocumentAccessLevel::FINANCE);

        try {
            $service->createDraft(
                $this->manager,
                'Финансов документ',
                'Не трябва да стане публично за всички живущи.',
                new DateTimeImmutable('2026-09-09 10:00:00 Europe/Sofia'),
                [$financeDocument],
            );
            self::fail('Restricted document must not be linked to an official announcement.');
        } catch (DomainException) {
            self::assertCount(0, $this->entityManager->getRepository(OfficialAnnouncement::class)->findAll());
        }

        $announcement = $service->createDraft(
            $this->manager,
            'Коректна чернова',
            'Текст.',
            new DateTimeImmutable('2026-09-09 10:00:00 Europe/Sofia'),
        );

        try {
            $service->revise(
                $this->manager,
                $announcement,
                'Променено заглавие',
                'Променен текст.',
                [$financeDocument],
            );
            self::fail('Restricted document must not be linked during revision.');
        } catch (DomainException) {
            self::assertSame('Коректна чернова', $announcement->getTitle());
            self::assertCount(0, $announcement->getDocuments());
        }
    }

    public function testPublicationSetsMetadataAndCreatesReceiptsOnlyForCurrentlyActiveUsers(): void
    {
        $service = $this->service();
        $announcement = $service->createDraft(
            $this->manager,
            'Предстоящо събрание',
            'Информация за събранието.',
            new DateTimeImmutable('2026-09-09 09:00:00 Europe/Sofia'),
        );
        $publishedAt = new DateTimeImmutable('2026-09-09 11:30:00 Europe/Sofia');

        $service->publish($this->admin, $announcement, $publishedAt);

        self::assertTrue($announcement->isPublished());
        self::assertSame($this->admin, $announcement->getPublishedBy());
        self::assertSame('2026-09-09T08:30:00+00:00', $announcement->getPublishedAt()?->format(DATE_ATOM));

        $receiptRepository = $this->entityManager->getRepository(AnnouncementReceipt::class);
        self::assertCount(5, $receiptRepository->findBy(['announcement' => $announcement]));
        foreach ([$this->manager, $this->admin, $this->resident, $this->cashier, $this->controller] as $activeUser) {
            self::assertNotNull($receiptRepository->findOneBy(['announcement' => $announcement, 'user' => $activeUser]));
        }
        self::assertNull($receiptRepository->findOneBy(['announcement' => $announcement, 'user' => $this->inactive]));

        $lateUser = $this->persistUser('late@example.com');
        $this->entityManager->flush();
        self::assertNull($receiptRepository->findOneBy(['announcement' => $announcement, 'user' => $lateUser]));
    }

    public function testPublishedAnnouncementIsImmutableAndSecondPublicationDoesNotAddReceipts(): void
    {
        $service = $this->service();
        $announcement = $service->createDraft(
            $this->manager,
            'Официално съобщение',
            'Финален текст.',
            new DateTimeImmutable('2026-09-09 09:00:00 Europe/Sofia'),
        );
        $service->publish(
            $this->manager,
            $announcement,
            new DateTimeImmutable('2026-09-09 10:00:00 Europe/Sofia'),
        );

        $announcementId = $announcement->getId();
        self::assertNotNull($announcementId);
        $countBefore = (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM announcement_receipt WHERE announcement_id = ?',
            [$announcementId],
        );

        try {
            $service->publish(
                $this->admin,
                $announcement,
                new DateTimeImmutable('2026-09-09 11:00:00 Europe/Sofia'),
            );
            self::fail('Published announcement must not be published twice.');
        } catch (LogicException) {
            $countAfter = (int) $this->entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM announcement_receipt WHERE announcement_id = ?',
                [$announcementId],
            );
            self::assertSame($countBefore, $countAfter);
        }

        $this->expectException(LogicException::class);
        $announcement->revise('Нова версия', 'Не трябва да се приложи.', []);
    }

    private function service(): OfficialAnnouncementService
    {
        self::assertTrue(class_exists(OfficialAnnouncementService::class), 'OfficialAnnouncementService has not been implemented yet.');

        return new OfficialAnnouncementService(
            $this->entityManager,
            new DocumentAccessPolicy(),
        );
    }

    /** @param list<string> $roles */
    private function persistUser(string $email, array $roles = [], bool $active = true): User
    {
        $person = new Person('Иван', 'Иванов', email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);
        if (!$active) {
            $user->deactivate();
        }

        $this->entityManager->persist($person);
        $this->entityManager->persist($user);

        return $user;
    }

    private function persistDocument(DocumentAccessLevel $accessLevel): Document
    {
        $document = Document::record(
            DocumentCategory::OTHER,
            $accessLevel,
            'Документ',
            null,
            'document.pdf',
            bin2hex(random_bytes(16)).'.pdf',
            'application/pdf',
            123,
            $this->manager,
            new DateTimeImmutable('2026-09-09 08:00:00 Europe/Sofia'),
        );
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }
}
