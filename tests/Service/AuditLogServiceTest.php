<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AuditEntry;
use App\Entity\Person;
use App\Entity\User;
use App\Service\AuditLogService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AuditLogServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $tool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testRecordParticipatesInCallerFlushAndPersistsActorSnapshot(): void
    {
        $person = new Person('Audit', 'Manager', email: 'audit-manager@example.com');
        $actor = new User($person, 'audit-manager@example.com', 'hash');
        $actor->setRoles(['ROLE_MANAGER']);
        $this->entityManager->persist($person);
        $this->entityManager->persist($actor);
        $this->entityManager->flush();

        $service = self::getContainer()->get(AuditLogService::class);
        self::assertInstanceOf(AuditLogService::class, $service);

        $entry = $service->record(
            $actor,
            'announcement.published',
            'OfficialAnnouncement',
            7,
            new DateTimeImmutable('2026-09-10 10:00:00', new DateTimeZone('UTC')),
            ['document_id' => 9],
        );

        self::assertNull($entry->getId());
        $this->entityManager->flush();
        self::assertNotNull($entry->getId());

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(AuditEntry::class)->find($entry->getId());
        self::assertInstanceOf(AuditEntry::class, $stored);
        self::assertSame('audit-manager@example.com', $stored->getActorIdentifier());
        self::assertSame(['document_id' => 9], $stored->getContext());
    }
}
