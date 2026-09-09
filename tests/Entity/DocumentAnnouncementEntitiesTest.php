<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AnnouncementReceipt;
use App\Entity\Document;
use App\Entity\OfficialAnnouncement;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Enum\OfficialAnnouncementStatus;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DocumentAnnouncementEntitiesTest extends TestCase
{
    public function testDocumentNormalizesMetadataAndStoresUtcTimestamp(): void
    {
        $this->assertDomainAvailable();
        $user = $this->user();

        $document = Document::record(
            DocumentCategory::HOUSE_RULES,
            DocumentAccessLevel::RESIDENTS,
            '  Правилник за вътрешния ред  ',
            '  Приет от общото събрание.  ',
            '  pravilnik.pdf  ',
            '0123456789abcdef0123456789abcdef.pdf',
            'application/pdf',
            1234,
            $user,
            new DateTimeImmutable('2026-09-09 09:30:00 Europe/Sofia'),
        );

        self::assertSame(DocumentCategory::HOUSE_RULES, $document->getCategory());
        self::assertSame(DocumentAccessLevel::RESIDENTS, $document->getAccessLevel());
        self::assertSame('Правилник за вътрешния ред', $document->getTitle());
        self::assertSame('Приет от общото събрание.', $document->getDescription());
        self::assertSame('pravilnik.pdf', $document->getOriginalName());
        self::assertSame('0123456789abcdef0123456789abcdef.pdf', $document->getStorageName());
        self::assertSame('application/pdf', $document->getMimeType());
        self::assertSame(1234, $document->getSizeBytes());
        self::assertSame($user, $document->getUploadedBy());
        self::assertSame('UTC', $document->getUploadedAt()->getTimezone()->getName());
        self::assertSame('2026-09-09 06:30:00', $document->getUploadedAt()->format('Y-m-d H:i:s'));
    }

    public function testDocumentRejectsBlankTitleInvalidStorageNameOrNonPositiveSize(): void
    {
        $this->assertDomainAvailable();
        $this->expectException(InvalidArgumentException::class);

        Document::record(
            DocumentCategory::OTHER,
            DocumentAccessLevel::RESIDENTS,
            '   ',
            null,
            'document.pdf',
            '../document.pdf',
            'application/pdf',
            0,
            $this->user(),
            new DateTimeImmutable(),
        );
    }

    public function testDocumentRejectsUnsupportedMimeType(): void
    {
        $this->assertDomainAvailable();
        $this->expectException(InvalidArgumentException::class);

        Document::record(
            DocumentCategory::OTHER,
            DocumentAccessLevel::RESIDENTS,
            'Документ',
            null,
            'document.txt',
            '0123456789abcdef0123456789abcdef.pdf',
            'text/plain',
            10,
            $this->user(),
            new DateTimeImmutable(),
        );
    }

    public function testAnnouncementCreatesEditableDraftWithResidentDocuments(): void
    {
        $this->assertDomainAvailable();
        $manager = $this->user('manager@example.com');
        $first = $this->document(DocumentAccessLevel::RESIDENTS, 'Покана');
        $second = $this->document(DocumentAccessLevel::RESIDENTS, 'Приложение');

        $announcement = OfficialAnnouncement::draft(
            '  Ремонт на входната врата  ',
            "  В понеделник ще бъде извършен ремонт.  ",
            $manager,
            new DateTimeImmutable('2026-09-09 10:00:00 Europe/Sofia'),
            [$first],
        );

        self::assertSame(OfficialAnnouncementStatus::DRAFT, $announcement->getStatus());
        self::assertSame('Ремонт на входната врата', $announcement->getTitle());
        self::assertSame('В понеделник ще бъде извършен ремонт.', $announcement->getBody());
        self::assertSame($manager, $announcement->getCreatedBy());
        self::assertSame('UTC', $announcement->getCreatedAt()->getTimezone()->getName());
        self::assertNull($announcement->getPublishedBy());
        self::assertNull($announcement->getPublishedAt());
        self::assertCount(1, $announcement->getDocuments());

        $announcement->revise('Нова тема', 'Нов текст', [$second]);

        self::assertSame('Нова тема', $announcement->getTitle());
        self::assertSame('Нов текст', $announcement->getBody());
        self::assertCount(1, $announcement->getDocuments());
        self::assertSame($second, $announcement->getDocuments()->first());
    }

    public function testAnnouncementRejectsFinanceOrManagementDocumentLink(): void
    {
        $this->assertDomainAvailable();
        $this->expectException(DomainException::class);

        OfficialAnnouncement::draft(
            'Финансов документ',
            'Този документ не трябва да се публикува към всички жители.',
            $this->user(),
            new DateTimeImmutable(),
            [$this->document(DocumentAccessLevel::FINANCE, 'Банково извлечение')],
        );
    }

    public function testPublishedAnnouncementCannotBeRevisedOrPublishedAgain(): void
    {
        $this->assertDomainAvailable();
        $manager = $this->user('manager@example.com');
        $announcement = OfficialAnnouncement::draft(
            'Важно съобщение',
            'Текст',
            $manager,
            new DateTimeImmutable('2026-09-09 07:00:00 UTC'),
        );
        $announcement->publish($manager, new DateTimeImmutable('2026-09-09 08:00:00 UTC'));

        self::assertTrue($announcement->isPublished());
        self::assertSame(OfficialAnnouncementStatus::PUBLISHED, $announcement->getStatus());
        self::assertSame($manager, $announcement->getPublishedBy());

        try {
            $announcement->revise('Променено', 'Променен текст', []);
            self::fail('Published announcement must not be revisable.');
        } catch (LogicException|DomainException) {
            self::assertSame('Важно съобщение', $announcement->getTitle());
        }

        $this->expectException(LogicException::class);
        $announcement->publish($manager, new DateTimeImmutable('2026-09-09 09:00:00 UTC'));
    }

    public function testAnnouncementRejectsPublicationBeforeCreation(): void
    {
        $this->assertDomainAvailable();
        $manager = $this->user('manager@example.com');
        $announcement = OfficialAnnouncement::draft(
            'Съобщение',
            'Текст',
            $manager,
            new DateTimeImmutable('2026-09-09 08:00:00 UTC'),
        );

        $this->expectException(InvalidArgumentException::class);
        $announcement->publish($manager, new DateTimeImmutable('2026-09-09 07:59:59 UTC'));
    }

    public function testReceiptRequiresPublishedAnnouncement(): void
    {
        $this->assertDomainAvailable();
        $announcement = OfficialAnnouncement::draft(
            'Чернова',
            'Текст',
            $this->user('manager@example.com'),
            new DateTimeImmutable('2026-09-09 08:00:00 UTC'),
        );

        $this->expectException(DomainException::class);
        AnnouncementReceipt::record($announcement, $this->user(), new DateTimeImmutable('2026-09-09 09:00:00 UTC'));
    }

    public function testReceiptStoresUtcAvailabilityAndKeepsFirstReadTimestamp(): void
    {
        $this->assertDomainAvailable();
        $manager = $this->user('manager@example.com');
        $resident = $this->user('resident@example.com');
        $announcement = OfficialAnnouncement::draft(
            'Съобщение',
            'Текст',
            $manager,
            new DateTimeImmutable('2026-09-09 07:00:00 UTC'),
        );
        $announcement->publish($manager, new DateTimeImmutable('2026-09-09 08:00:00 UTC'));

        $receipt = AnnouncementReceipt::record(
            $announcement,
            $resident,
            new DateTimeImmutable('2026-09-09 11:00:00 Europe/Sofia'),
        );

        self::assertFalse($receipt->isRead());
        self::assertSame('UTC', $receipt->getAvailableAt()->getTimezone()->getName());
        self::assertSame('2026-09-09 08:00:00', $receipt->getAvailableAt()->format('Y-m-d H:i:s'));

        $firstReadAt = new DateTimeImmutable('2026-09-09 11:15:00 Europe/Sofia');
        $receipt->markRead($firstReadAt);
        $receipt->markRead(new DateTimeImmutable('2026-09-09 12:00:00 Europe/Sofia'));

        self::assertTrue($receipt->isRead());
        self::assertNotNull($receipt->getReadAt());
        self::assertSame('2026-09-09 08:15:00', $receipt->getReadAt()->format('Y-m-d H:i:s'));
    }

    public function testReceiptRejectsFirstReadBeforeAvailability(): void
    {
        $this->assertDomainAvailable();
        $manager = $this->user('manager@example.com');
        $announcement = OfficialAnnouncement::draft(
            'Съобщение',
            'Текст',
            $manager,
            new DateTimeImmutable('2026-09-09 07:00:00 UTC'),
        );
        $announcement->publish($manager, new DateTimeImmutable('2026-09-09 08:00:00 UTC'));
        $receipt = AnnouncementReceipt::record(
            $announcement,
            $this->user(),
            new DateTimeImmutable('2026-09-09 09:00:00 UTC'),
        );

        $this->expectException(InvalidArgumentException::class);
        $receipt->markRead(new DateTimeImmutable('2026-09-09 08:59:59 UTC'));
    }

    private function assertDomainAvailable(): void
    {
        self::assertTrue(
            class_exists(Document::class)
            && class_exists(OfficialAnnouncement::class)
            && class_exists(AnnouncementReceipt::class)
            && enum_exists(DocumentCategory::class)
            && enum_exists(DocumentAccessLevel::class)
            && enum_exists(OfficialAnnouncementStatus::class),
            'Phase 8 document/announcement domain has not been implemented yet.',
        );
    }

    private function document(DocumentAccessLevel $accessLevel, string $title): Document
    {
        return Document::record(
            DocumentCategory::OTHER,
            $accessLevel,
            $title,
            null,
            'document.pdf',
            '0123456789abcdef0123456789abcdef.pdf',
            'application/pdf',
            100,
            $this->user('uploader-'.md5($title).'@example.com'),
            new DateTimeImmutable('2026-09-09 08:00:00 UTC'),
        );
    }

    private function user(string $email = 'resident@example.com'): User
    {
        $person = new Person('Иван', 'Иванов', email: $email);

        return new User($person, $email, 'hash');
    }
}
