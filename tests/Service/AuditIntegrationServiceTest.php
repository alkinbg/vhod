<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AuditEntry;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\PaymentSource;
use App\Service\AuditLogService;
use App\Service\ComplianceRegistryService;
use App\Service\OfficialAnnouncementService;
use App\Service\PaymentAllocator;
use App\Service\PaymentPostingService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AuditIntegrationServiceTest extends KernelTestCase
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

    public function testComplianceActionIsAuditedWithAuthenticatedActor(): void
    {
        $manager = $this->persistUser('audit-compliance@example.com', ['ROLE_MANAGER']);
        $service = self::getContainer()->get(ComplianceRegistryService::class);
        self::assertInstanceOf(ComplianceRegistryService::class, $service);

        $service->updateRegistryData(
            $manager,
            'EISES-AUDIT-1',
            null,
            null,
            new DateTimeImmutable('2026-09-10 10:00:00', new DateTimeZone('UTC')),
        );

        $entry = $this->singleAuditEntry();
        self::assertSame('compliance.registry.updated', $entry->getAction());
        self::assertSame('audit-compliance@example.com', $entry->getActorIdentifier());
        self::assertSame('CondominiumProfile', $entry->getSubjectType());
        self::assertNotNull($entry->getSubjectId());
    }

    public function testOfficialPublicationIsAuditedOnce(): void
    {
        $manager = $this->persistUser('audit-announcement@example.com', ['ROLE_MANAGER']);
        $service = self::getContainer()->get(OfficialAnnouncementService::class);
        self::assertInstanceOf(OfficialAnnouncementService::class, $service);
        $createdAt = new DateTimeImmutable('2026-09-10 10:00:00', new DateTimeZone('UTC'));
        $announcement = $service->createDraft($manager, 'Проверка', 'Audit публикация', $createdAt);

        $service->publish($manager, $announcement, $createdAt->modify('+5 minutes'));

        $entry = $this->singleAuditEntry();
        self::assertSame('announcement.published', $entry->getAction());
        self::assertSame($announcement->getId(), $entry->getSubjectId());
        self::assertSame(1, $entry->getContext()['recipient_count']);
    }

    public function testIdempotentPaymentDoesNotDuplicateSystemAudit(): void
    {
        $unit = new Unit('A-1');
        $this->entityManager->persist($unit);
        $this->entityManager->flush();

        $service = new PaymentPostingService(
            $this->entityManager,
            new PaymentAllocator($this->entityManager),
            new AuditLogService($this->entityManager),
        );
        $receivedAt = new DateTimeImmutable('2026-09-10 10:00:00', new DateTimeZone('UTC'));
        $postedAt = $receivedAt->modify('+1 minute');

        $first = $service->post(
            $unit,
            500,
            PaymentSource::BANK_TRANSFER,
            $receivedAt,
            $postedAt,
            externalReference: 'audit-payment-1',
        );
        $second = $service->post(
            $unit,
            500,
            PaymentSource::BANK_TRANSFER,
            $receivedAt,
            $postedAt->modify('+1 minute'),
            externalReference: 'audit-payment-1',
        );

        self::assertSame($first->payment->getId(), $second->payment->getId());
        $entries = $this->entityManager->getRepository(AuditEntry::class)->findAll();
        self::assertCount(1, $entries);
        self::assertSame('finance.payment.posted', $entries[0]->getAction());
        self::assertSame('system', $entries[0]->getActorIdentifier());
    }

    /** @param list<string> $roles */
    private function persistUser(string $email, array $roles): User
    {
        $person = new Person('Audit', 'User', email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);
        $this->entityManager->persist($person);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function singleAuditEntry(): AuditEntry
    {
        $entries = $this->entityManager->getRepository(AuditEntry::class)->findAll();
        self::assertCount(1, $entries);
        self::assertInstanceOf(AuditEntry::class, $entries[0]);

        return $entries[0];
    }
}
