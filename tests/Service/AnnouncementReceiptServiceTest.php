<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AnnouncementReceipt;
use App\Entity\OfficialAnnouncement;
use App\Entity\Person;
use App\Entity\User;
use App\Repository\AnnouncementReceiptRepository;
use App\Service\AnnouncementReceiptService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AnnouncementReceiptServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private User $manager;
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

        $this->manager = $this->persistUser('manager@example.com', ['ROLE_MANAGER']);
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

    public function testMarkReadReturnsTrueAndPreservesFirstReadTimestamp(): void
    {
        $announcement = $this->publishedAnnouncement('Първо съобщение', '2026-09-09 09:00:00 Europe/Sofia');
        $receipt = AnnouncementReceipt::record(
            $announcement,
            $this->resident,
            new DateTimeImmutable('2026-09-09 10:00:00 Europe/Sofia'),
        );
        $this->entityManager->persist($receipt);
        $this->entityManager->flush();

        $service = $this->service();
        self::assertTrue($service->markRead(
            $this->resident,
            $announcement,
            new DateTimeImmutable('2026-09-09 10:15:00 Europe/Sofia'),
        ));
        self::assertTrue($service->markRead(
            $this->resident,
            $announcement,
            new DateTimeImmutable('2026-09-09 11:30:00 Europe/Sofia'),
        ));

        self::assertSame('2026-09-09T07:15:00+00:00', $receipt->getReadAt()?->format(DATE_ATOM));
        self::assertSame(0, $service->countUnread($this->resident));
    }

    public function testMarkReadReturnsFalseWhenReceiptDoesNotExistAndNeverBackfillsOne(): void
    {
        $announcement = $this->publishedAnnouncement('Само за съществуващи', '2026-09-09 09:00:00 Europe/Sofia');
        $service = $this->service();

        self::assertFalse($service->markRead(
            $this->resident,
            $announcement,
            new DateTimeImmutable('2026-09-09 11:00:00 Europe/Sofia'),
        ));
        self::assertCount(0, $this->entityManager->getRepository(AnnouncementReceipt::class)->findAll());
    }

    public function testUnreadQueriesAreScopedToCurrentUserAndRespectLimit(): void
    {
        $older = $this->publishedAnnouncement('По-старо', '2026-09-09 08:00:00 Europe/Sofia');
        $newer = $this->publishedAnnouncement('По-ново', '2026-09-09 10:00:00 Europe/Sofia');
        $read = $this->publishedAnnouncement('Прочетено', '2026-09-09 09:00:00 Europe/Sofia');

        $olderReceipt = AnnouncementReceipt::record($older, $this->resident, new DateTimeImmutable('2026-09-09 08:30:00 Europe/Sofia'));
        $newerReceipt = AnnouncementReceipt::record($newer, $this->resident, new DateTimeImmutable('2026-09-09 10:30:00 Europe/Sofia'));
        $readReceipt = AnnouncementReceipt::record($read, $this->resident, new DateTimeImmutable('2026-09-09 09:30:00 Europe/Sofia'));
        $readReceipt->markRead(new DateTimeImmutable('2026-09-09 09:45:00 Europe/Sofia'));
        $otherReceipt = AnnouncementReceipt::record($newer, $this->otherResident, new DateTimeImmutable('2026-09-09 10:30:00 Europe/Sofia'));

        foreach ([$olderReceipt, $newerReceipt, $readReceipt, $otherReceipt] as $receipt) {
            $this->entityManager->persist($receipt);
        }
        $this->entityManager->flush();

        $service = $this->service();
        self::assertSame(2, $service->countUnread($this->resident));
        self::assertSame(1, $service->countUnread($this->otherResident));

        $limited = $service->findUnread($this->resident, 1);
        self::assertCount(1, $limited);
        self::assertSame($newer, $limited[0]->getAnnouncement());

        self::assertSame([$newer->getId(), $older->getId()], $service->unreadAnnouncementIds($this->resident));
        self::assertSame([$newer->getId()], $service->unreadAnnouncementIds($this->otherResident));
    }

    private function service(): AnnouncementReceiptService
    {
        self::assertTrue(class_exists(AnnouncementReceiptService::class), 'AnnouncementReceiptService has not been implemented yet.');

        $repository = $this->entityManager->getRepository(AnnouncementReceipt::class);
        self::assertInstanceOf(AnnouncementReceiptRepository::class, $repository);

        return new AnnouncementReceiptService($this->entityManager, $repository);
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

    private function publishedAnnouncement(string $title, string $createdAt): OfficialAnnouncement
    {
        $created = new DateTimeImmutable($createdAt);
        $announcement = OfficialAnnouncement::draft($title, 'Текст.', $this->manager, $created);
        $announcement->publish($this->manager, $created->modify('+30 minutes'));
        $this->entityManager->persist($announcement);
        $this->entityManager->flush();

        return $announcement;
    }
}
