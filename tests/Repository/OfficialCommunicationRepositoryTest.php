<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\AnnouncementReceipt;
use App\Entity\Document;
use App\Entity\OfficialAnnouncement;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Repository\AnnouncementReceiptRepository;
use App\Repository\DocumentRepository;
use App\Repository\OfficialAnnouncementRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OfficialCommunicationRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private User $resident;
    private User $otherResident;

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

        $this->resident = $this->persistUser('resident@example.com');
        $this->otherResident = $this->persistUser('other@example.com');
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->isOpen()) {
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testDocumentRepositoryFiltersAllowedLevelsAndCategoryInSql(): void
    {
        $repository = $this->documentRepository();
        $residentRules = $this->persistDocument(DocumentAccessLevel::RESIDENTS, DocumentCategory::HOUSE_RULES, 'Правилник', '2026-09-09 08:00:00');
        $residentOther = $this->persistDocument(DocumentAccessLevel::RESIDENTS, DocumentCategory::OTHER, 'Друг документ', '2026-09-09 09:00:00');
        $finance = $this->persistDocument(DocumentAccessLevel::FINANCE, DocumentCategory::BANK_STATEMENT, 'Извлечение', '2026-09-09 10:00:00');
        $management = $this->persistDocument(DocumentAccessLevel::MANAGEMENT, DocumentCategory::OTHER, 'Управление', '2026-09-09 11:00:00');
        $this->entityManager->flush();

        self::assertSame([$residentOther, $residentRules], $repository->findVisible([DocumentAccessLevel::RESIDENTS]));
        self::assertSame(
            [$residentRules],
            $repository->findVisible([DocumentAccessLevel::RESIDENTS], DocumentCategory::HOUSE_RULES),
        );
        self::assertSame(
            [$finance, $residentOther, $residentRules],
            $repository->findVisible([DocumentAccessLevel::RESIDENTS, DocumentAccessLevel::FINANCE]),
        );
        self::assertSame([], $repository->findVisible([]));
        self::assertNotContains($management, $repository->findVisible([DocumentAccessLevel::RESIDENTS, DocumentAccessLevel::FINANCE]));
    }

    public function testAnnouncementRepositoryReturnsOnlyPublishedNewestFirst(): void
    {
        $repository = $this->announcementRepository();
        $manager = $this->persistUser('manager@example.com');
        $older = OfficialAnnouncement::draft('По-старо', 'Текст', $manager, new DateTimeImmutable('2026-09-09 07:00:00 UTC'));
        $older->publish($manager, new DateTimeImmutable('2026-09-09 08:00:00 UTC'));
        $newer = OfficialAnnouncement::draft('По-ново', 'Текст', $manager, new DateTimeImmutable('2026-09-09 08:30:00 UTC'));
        $newer->publish($manager, new DateTimeImmutable('2026-09-09 09:00:00 UTC'));
        $draft = OfficialAnnouncement::draft('Чернова', 'Текст', $manager, new DateTimeImmutable('2026-09-09 10:00:00 UTC'));
        foreach ([$older, $newer, $draft] as $announcement) {
            $this->entityManager->persist($announcement);
        }
        $this->entityManager->flush();

        self::assertSame([$newer, $older], $repository->findPublished());
    }

    public function testReceiptRepositoryScopesUnreadStateAndReadStatistics(): void
    {
        $repository = $this->receiptRepository();
        $manager = $this->persistUser('manager@example.com');
        $announcement = OfficialAnnouncement::draft('Съобщение', 'Текст', $manager, new DateTimeImmutable('2026-09-09 07:00:00 UTC'));
        $announcement->publish($manager, new DateTimeImmutable('2026-09-09 08:00:00 UTC'));
        $otherAnnouncement = OfficialAnnouncement::draft('Второ', 'Текст', $manager, new DateTimeImmutable('2026-09-09 08:30:00 UTC'));
        $otherAnnouncement->publish($manager, new DateTimeImmutable('2026-09-09 09:00:00 UTC'));
        $this->entityManager->persist($announcement);
        $this->entityManager->persist($otherAnnouncement);

        $residentUnread = AnnouncementReceipt::record($announcement, $this->resident, new DateTimeImmutable('2026-09-09 08:00:00 UTC'));
        $residentNewerUnread = AnnouncementReceipt::record($otherAnnouncement, $this->resident, new DateTimeImmutable('2026-09-09 09:00:00 UTC'));
        $otherRead = AnnouncementReceipt::record($announcement, $this->otherResident, new DateTimeImmutable('2026-09-09 08:00:00 UTC'));
        $otherRead->markRead(new DateTimeImmutable('2026-09-09 08:15:00 UTC'));
        foreach ([$residentUnread, $residentNewerUnread, $otherRead] as $receipt) {
            $this->entityManager->persist($receipt);
        }
        $this->entityManager->flush();

        self::assertSame($residentUnread, $repository->findFor($this->resident, $announcement));
        self::assertSame(2, $repository->countUnreadFor($this->resident));
        self::assertSame(0, $repository->countUnreadFor($this->otherResident));
        self::assertSame([$residentNewerUnread, $residentUnread], $repository->findUnreadFor($this->resident, 5));
        self::assertSame([$residentNewerUnread], $repository->findUnreadFor($this->resident, 1));
        self::assertSame(2, $repository->countForAnnouncement($announcement));
        self::assertSame(1, $repository->countReadForAnnouncement($announcement));
    }

    private function documentRepository(): DocumentRepository
    {
        self::assertTrue(class_exists(DocumentRepository::class), 'DocumentRepository has not been implemented yet.');
        $repository = self::getContainer()->get(DocumentRepository::class);
        self::assertInstanceOf(DocumentRepository::class, $repository);

        return $repository;
    }

    private function announcementRepository(): OfficialAnnouncementRepository
    {
        self::assertTrue(class_exists(OfficialAnnouncementRepository::class), 'OfficialAnnouncementRepository has not been implemented yet.');
        $repository = self::getContainer()->get(OfficialAnnouncementRepository::class);
        self::assertInstanceOf(OfficialAnnouncementRepository::class, $repository);

        return $repository;
    }

    private function receiptRepository(): AnnouncementReceiptRepository
    {
        self::assertTrue(class_exists(AnnouncementReceiptRepository::class), 'AnnouncementReceiptRepository has not been implemented yet.');
        $repository = self::getContainer()->get(AnnouncementReceiptRepository::class);
        self::assertInstanceOf(AnnouncementReceiptRepository::class, $repository);

        return $repository;
    }

    private function persistUser(string $email): User
    {
        $person = new Person('Иван', 'Иванов', email: $email);
        $user = new User($person, $email, 'hash');
        $this->entityManager->persist($person);
        $this->entityManager->persist($user);

        return $user;
    }

    private function persistDocument(
        DocumentAccessLevel $accessLevel,
        DocumentCategory $category,
        string $title,
        string $uploadedAt,
    ): Document {
        $document = Document::record(
            $category,
            $accessLevel,
            $title,
            null,
            'document.pdf',
            bin2hex(random_bytes(16)).'.pdf',
            'application/pdf',
            100,
            $this->resident,
            new DateTimeImmutable($uploadedAt.' UTC'),
        );
        $this->entityManager->persist($document);

        return $document;
    }
}
