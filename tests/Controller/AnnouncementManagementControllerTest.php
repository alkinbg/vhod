<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AnnouncementReceipt;
use App\Entity\Document;
use App\Entity\OfficialAnnouncement;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Enum\OfficialAnnouncementStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AnnouncementManagementControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $residentId;
    private int $managerId;
    private int $activeResidentId;
    private int $inactiveResidentId;
    private int $residentDocumentId;
    private int $financeDocumentId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = $this->entityManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->residentId = $this->persistUser($em, 'resident-ann-mgmt@example.com');
        $this->managerId = $this->persistUser($em, 'manager-ann-mgmt@example.com', ['ROLE_MANAGER']);
        $this->activeResidentId = $this->persistUser($em, 'active-ann-mgmt@example.com');
        $this->inactiveResidentId = $this->persistUser($em, 'inactive-ann-mgmt@example.com', [], false);
        $manager = $this->user($this->managerId);
        $this->residentDocumentId = $this->persistDocument($em, $manager, DocumentAccessLevel::RESIDENTS, 'Правилник');
        $this->financeDocumentId = $this->persistDocument($em, $manager, DocumentAccessLevel::FINANCE, 'Фактура');
        $em->flush();
    }

    public function testResidentCannotOpenAnnouncementManagement(): void
    {
        $this->client->loginUser($this->user($this->residentId));
        $this->client->request('GET', '/management/announcements');
        self::assertResponseStatusCodeSame(403);
    }

    public function testManagerCanCreateEditAndPublishDraft(): void
    {
        $this->client->loginUser($this->user($this->managerId));

        $crawler = $this->client->request('GET', '/management/announcement/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('announcement_submit')->form([
            'title' => 'Проверка на асансьора',
            'body' => 'Проверката ще бъде извършена утре.',
            'document_ids' => [(string) $this->residentDocumentId],
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/management/announcements');

        $announcement = $this->entityManager()->getRepository(OfficialAnnouncement::class)->findOneBy([]);
        self::assertInstanceOf(OfficialAnnouncement::class, $announcement);
        self::assertSame(OfficialAnnouncementStatus::DRAFT, $announcement->getStatus());
        self::assertCount(1, $announcement->getDocuments());
        self::assertNotNull($announcement->getId());

        $crawler = $this->client->request('GET', '/management/announcement/'.$announcement->getId().'/edit');
        $form = $crawler->selectButton('announcement_submit')->form([
            'title' => 'Проверка на асансьора — промяна',
            'body' => 'Проверката ще бъде извършена в петък.',
            'document_ids' => [],
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/management/announcements');

        $em = $this->entityManager();
        $em->clear();
        $announcement = $em->find(OfficialAnnouncement::class, $announcement->getId());
        self::assertInstanceOf(OfficialAnnouncement::class, $announcement);
        self::assertSame('Проверка на асансьора — промяна', $announcement->getTitle());
        self::assertCount(0, $announcement->getDocuments());

        $crawler = $this->client->request('GET', '/management/announcements');
        $form = $crawler->selectButton('publish_'.$announcement->getId())->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/management/announcements');

        $em = $this->entityManager();
        $em->clear();
        $announcement = $em->find(OfficialAnnouncement::class, $announcement->getId());
        self::assertInstanceOf(OfficialAnnouncement::class, $announcement);
        self::assertTrue($announcement->isPublished());

        $receipts = $em->getRepository(AnnouncementReceipt::class)->findBy(['announcement' => $announcement]);
        self::assertCount(3, $receipts);
        self::assertNull($em->getRepository(AnnouncementReceipt::class)->findOneBy([
            'announcement' => $announcement,
            'user' => $em->find(User::class, $this->inactiveResidentId),
        ]));

        $this->client->request('GET', '/management/announcement/'.$announcement->getId().'/edit');
        self::assertResponseStatusCodeSame(422);
    }

    public function testRestrictedDocumentCannotBeAttached(): void
    {
        $this->client->loginUser($this->user($this->managerId));
        $crawler = $this->client->request('GET', '/management/announcement/new');
        $form = $crawler->selectButton('announcement_submit')->form([
            'title' => 'Невалидна обява',
            'body' => 'Опит за прикачване на финансов документ.',
        ]);
        $form['document_ids']->disableValidation();
        $form['document_ids']->setValue([(string) $this->financeDocumentId]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->entityManager()->getRepository(OfficialAnnouncement::class)->findAll());
    }

    public function testInvalidCsrfDoesNotPublish(): void
    {
        $em = $this->entityManager();
        $manager = $this->user($this->managerId);
        $announcement = OfficialAnnouncement::draft('Чернова', 'Текст на черновата.', $manager, new DateTimeImmutable('2026-09-09 08:00:00+00:00'));
        $em->persist($announcement);
        $em->flush();
        self::assertNotNull($announcement->getId());

        $this->client->loginUser($manager);
        $this->client->request('POST', '/management/announcement/'.$announcement->getId().'/publish', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $em = $this->entityManager();
        $em->clear();
        $announcement = $em->find(OfficialAnnouncement::class, $announcement->getId());
        self::assertInstanceOf(OfficialAnnouncement::class, $announcement);
        self::assertSame(OfficialAnnouncementStatus::DRAFT, $announcement->getStatus());
        self::assertCount(0, $em->getRepository(AnnouncementReceipt::class)->findAll());
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        return $em;
    }

    /** @param list<string> $roles */
    private function persistUser(EntityManagerInterface $em, string $email, array $roles = [], bool $active = true): int
    {
        $person = new Person('Иван', 'Иванов', email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);
        if (!$active) {
            $user->deactivate();
        }
        $em->persist($person);
        $em->persist($user);
        $em->flush();
        self::assertNotNull($user->getId());
        return $user->getId();
    }

    private function persistDocument(EntityManagerInterface $em, User $manager, DocumentAccessLevel $level, string $title): int
    {
        $document = Document::record(
            DocumentCategory::OTHER,
            $level,
            $title,
            null,
            'file.pdf',
            bin2hex(random_bytes(16)).'.pdf',
            'application/pdf',
            100,
            $manager,
            new DateTimeImmutable('2026-09-09 08:00:00+00:00'),
        );
        $em->persist($document);
        $em->flush();
        self::assertNotNull($document->getId());
        return $document->getId();
    }

    private function user(int $id): User
    {
        $user = $this->entityManager()->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);
        return $user;
    }
}
