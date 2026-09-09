<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\AnnouncementReceipt;
use App\Entity\Document;
use App\Entity\OfficialAnnouncement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentsAnnouncementsSchemaTest extends KernelTestCase
{
    public function testDocumentUsesNamedUniqueAndQueryIndexes(): void
    {
        $metadata = $this->metadata(Document::class);

        self::assertSame(
            ['access_level', 'category', 'uploaded_at'],
            $metadata->table['indexes']['idx_document_access_category_uploaded']['columns'] ?? null,
        );
        self::assertSame(
            ['uploaded_by_id'],
            $metadata->table['indexes']['idx_document_uploaded_by']['columns'] ?? null,
        );
        self::assertSame(
            ['storage_name'],
            $metadata->table['uniqueConstraints']['uniq_document_storage_name']['columns'] ?? null,
        );
    }

    public function testOfficialAnnouncementUsesNamedPublicationAndActorIndexes(): void
    {
        $metadata = $this->metadata(OfficialAnnouncement::class);

        self::assertSame(
            ['status', 'published_at'],
            $metadata->table['indexes']['idx_official_announcement_status_published']['columns'] ?? null,
        );
        self::assertSame(
            ['created_by_id'],
            $metadata->table['indexes']['idx_official_announcement_created_by']['columns'] ?? null,
        );
        self::assertSame(
            ['published_by_id'],
            $metadata->table['indexes']['idx_official_announcement_published_by']['columns'] ?? null,
        );
    }

    public function testAnnouncementReceiptUsesNamedUniqueAndUnreadIndexes(): void
    {
        $metadata = $this->metadata(AnnouncementReceipt::class);

        self::assertSame(
            ['announcement_id', 'user_id'],
            $metadata->table['uniqueConstraints']['uniq_announcement_receipt_announcement_user']['columns'] ?? null,
        );
        self::assertSame(
            ['user_id', 'read_at'],
            $metadata->table['indexes']['idx_announcement_receipt_user_read']['columns'] ?? null,
        );
        self::assertSame(
            ['announcement_id'],
            $metadata->table['indexes']['idx_announcement_receipt_announcement']['columns'] ?? null,
        );
    }

    public function testEveryPhase8AssociationUsesRestrictAndNeverCascadeRemove(): void
    {
        $document = $this->metadata(Document::class);
        $announcement = $this->metadata(OfficialAnnouncement::class);
        $receipt = $this->metadata(AnnouncementReceipt::class);

        $this->assertToOneRestrict($document->getAssociationMapping('uploadedBy'));
        $this->assertToOneRestrict($announcement->getAssociationMapping('createdBy'));
        $this->assertToOneRestrict($announcement->getAssociationMapping('publishedBy'));
        $this->assertToOneRestrict($receipt->getAssociationMapping('announcement'));
        $this->assertToOneRestrict($receipt->getAssociationMapping('user'));

        $documents = $announcement->getAssociationMapping('documents');
        self::assertTrue($documents->isManyToManyOwningSide());
        self::assertSame('RESTRICT', $documents->joinTable->joinColumns[0]->onDelete);
        self::assertSame('RESTRICT', $documents->joinTable->inverseJoinColumns[0]->onDelete);
        self::assertFalse($documents->isCascadeRemove());
    }

    /** @param class-string $class */
    private function metadata(string $class): ClassMetadata
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getClassMetadata($class);
    }

    private function assertToOneRestrict(AssociationMapping $association): void
    {
        self::assertTrue($association->isToOneOwningSide());
        self::assertSame('RESTRICT', $association->joinColumns[0]->onDelete);
        self::assertFalse($association->isCascadeRemove());
    }
}
