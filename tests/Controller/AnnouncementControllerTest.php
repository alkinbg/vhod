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
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AnnouncementControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $residentId;
    private int $managerId;
    private int $documentId;
    private int $draftId;
    private int $olderAnnouncementId;
    private int $newerAnnouncementId;
    private int $historicalAnnouncementId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = $this->entityManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $resident = $this->persistUser($em, 'resident-announcements@example.com');
        $manager = $this->persistUser($em, 'manager-announcements@example.com', ['ROLE_MANAGER']);
        $em->flush();
        self::assertNotNull($resident->getId());
        self::assertNotNull($manager->getId());
        $this->residentId = $resident->getId();
        $this->managerId = $manager->getId();

        $document = Document::record(DocumentCategory::HOUSE_RULES, DocumentAccessLevel::RESIDENTS, 'Правилник за вътрешния ред', 'Документ към официалната обява.', 'house-rules.pdf', str_repeat('a', 32).'.pdf', 'application/pdf', 100, $manager, new DateTimeImmutable('2026-09-09 06:00:00+00:00'));
        $em->persist($document);
        $draft = OfficialAnnouncement::draft('Чернова за ремонт', 'Тази обява още не е публикувана.', $manager, new DateTimeImmutable('2026-09-09 07:00:00+00:00'));
        $em->persist($draft);
        $older = OfficialAnnouncement::draft('Проверка на асансьора', "Проверката е в петък.\nМоля, освободете достъпа.", $manager, new DateTimeImmutable('2026-09-09 07:10:00+00:00'), [$document]);
        $older->publish($manager, new DateTimeImmutable('2026-09-09 08:00:00+00:00'));
        $em->persist($older);
        $em->persist(AnnouncementReceipt::record($older, $resident, new DateTimeImmutable('2026-09-09 08:00:00+00:00')));
        $newer = OfficialAnnouncement::draft('Спиране на водата', 'Водата ще бъде спряна за кратка профилактика.', $manager, new DateTimeImmutable('2026-09-09 08:10:00+00:00'));
        $newer->publish($manager, new DateTimeImmutable('2026-09-09 09:00:00+00:00'));
        $em->persist($newer);
        $em->persist(AnnouncementReceipt::record($newer, $resident, new DateTimeImmutable('2026-09-09 09:00:00+00:00')));
        $historical = OfficialAnnouncement::draft('Стара публикувана обява', 'Достъпна е, но няма ретроактивно известие.', $manager, new DateTimeImmutable('2026-09-08 07:00:00+00:00'));
        $historical->publish($manager, new DateTimeImmutable('2026-09-08 08:00:00+00:00'));
        $em->persist($historical);
        $em->flush();

        self::assertNotNull($document->getId()); self::assertNotNull($draft->getId()); self::assertNotNull($older->getId()); self::assertNotNull($newer->getId()); self::assertNotNull($historical->getId());
        $this->documentId = $document->getId(); $this->draftId = $draft->getId(); $this->olderAnnouncementId = $older->getId(); $this->newerAnnouncementId = $newer->getId(); $this->historicalAnnouncementId = $historical->getId();
    }

    public function testAnonymousIsRedirectedAndDraftIsNotExposed(): void
    {
        $this->client->request('GET', '/announcements'); self::assertResponseRedirects('/login');
        $this->client->loginUser($this->resident()); $this->client->request('GET', '/announcement/'.$this->draftId); self::assertResponseStatusCodeSame(404);
    }

    public function testResidentListsPublishedAnnouncementsNewestFirstAndSeesUnreadState(): void
    {
        $this->client->loginUser($this->resident()); $this->client->request('GET', '/announcements');
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Чернова за ремонт', $content); self::assertStringContainsString('Спиране на водата', $content); self::assertStringContainsString('Проверка на асансьора', $content); self::assertStringContainsString('Стара публикувана обява', $content);
        self::assertLessThan(strpos($content, 'Проверка на асансьора'), strpos($content, 'Спиране на водата'));
        self::assertStringContainsString('Непрочетено', $content);
        self::assertSelectorExists('[data-testid="announcement-list"] .list-group-item');
        self::assertSelectorExists('.page-header .page-title');
    }

    public function testPublishedDetailShowsOfficialMetadataDocumentAndViberAction(): void
    {
        $this->client->loginUser($this->resident()); $this->client->request('GET', '/announcement/'.$this->olderAnnouncementId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Официално'); self::assertSelectorTextContains('body', 'Проверка на асансьора'); self::assertSelectorTextContains('body', 'Правилник за вътрешния ред');
        self::assertSelectorExists('a[href="/document/'.$this->documentId.'/download"]');
        self::assertSelectorExists('[data-testid="announcement-detail"].card');
        self::assertSelectorExists('[data-testid="announcement-detail"] .card-body');
        $content = (string) $this->client->getResponse()->getContent(); self::assertStringContainsString('viber://forward?text=', $content); self::assertStringNotContainsString('Коментар', $content); self::assertStringNotContainsString('Харесва', $content);
    }

    public function testReadMutationIsCsrfProtectedAndIdempotent(): void
    {
        $this->client->loginUser($this->resident()); $crawler = $this->client->request('GET', '/announcement/'.$this->olderAnnouncementId); self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('announcement_read_submit')->form(); $token = $form->get('_token')->getValue(); self::assertIsString($token);
        $this->client->submit($form); self::assertResponseRedirects('/announcement/'.$this->olderAnnouncementId);
        $em = $this->entityManager(); $em->clear(); $receipt = $em->getRepository(AnnouncementReceipt::class)->findOneBy(['announcement' => $em->find(OfficialAnnouncement::class, $this->olderAnnouncementId), 'user' => $em->find(User::class, $this->residentId)]); self::assertInstanceOf(AnnouncementReceipt::class, $receipt); self::assertNotNull($receipt->getReadAt()); $firstReadAt = $receipt->getReadAt();
        $this->client->request('POST', '/announcement/'.$this->olderAnnouncementId.'/read', ['_token' => $token]); self::assertResponseRedirects('/announcement/'.$this->olderAnnouncementId);
        $em->clear(); $receipt = $em->getRepository(AnnouncementReceipt::class)->findOneBy(['announcement' => $em->find(OfficialAnnouncement::class, $this->olderAnnouncementId), 'user' => $em->find(User::class, $this->residentId)]); self::assertInstanceOf(AnnouncementReceipt::class, $receipt); self::assertEquals($firstReadAt, $receipt->getReadAt());
        $this->client->request('POST', '/announcement/'.$this->newerAnnouncementId.'/read', ['_token' => 'invalid']); self::assertResponseStatusCodeSame(403);
    }

    public function testHistoricalAnnouncementWithoutReceiptIsBrowsableWithoutBackfill(): void
    {
        $this->client->loginUser($this->resident()); $this->client->request('GET', '/announcement/'.$this->historicalAnnouncementId); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('body', 'Стара публикувана обява'); self::assertSelectorNotExists('button[name="announcement_read_submit"]');
        $em = $this->entityManager(); $receipt = $em->getRepository(AnnouncementReceipt::class)->findOneBy(['announcement' => $em->find(OfficialAnnouncement::class, $this->historicalAnnouncementId), 'user' => $em->find(User::class, $this->residentId)]); self::assertNull($receipt);
    }

    public function testPublishedAnnouncementHasPrintAndPdfOutputs(): void
    {
        $this->client->loginUser($this->resident()); $this->client->request('GET', '/announcement/'.$this->olderAnnouncementId.'/print'); self::assertResponseIsSuccessful(); self::assertResponseHeaderSame('content-type', 'text/html; charset=UTF-8'); self::assertSelectorTextContains('body', 'Проверка на асансьора'); self::assertSelectorTextContains('body', 'Правилник за вътрешния ред');
        $this->client->request('GET', '/announcement/'.$this->olderAnnouncementId.'/pdf'); self::assertResponseIsSuccessful(); self::assertResponseHeaderSame('content-type', 'application/pdf'); self::assertResponseHeaderSame('content-disposition', 'attachment; filename=announcement-'.$this->olderAnnouncementId.'.pdf'); self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
    }

    public function testResidentNavigationShowsOfficialLinksUnreadBadgeAndNoManagementActions(): void
    {
        $this->client->loginUser($this->resident()); $this->client->request('GET', '/announcements'); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('nav a[href="/announcements"]', 'Обяви'); self::assertSelectorExists('nav a[href="/documents"]'); self::assertSelectorTextSame('nav .nav-unread-badge', '2'); self::assertSelectorNotExists('nav a[href="/management/announcements"]'); self::assertSelectorNotExists('nav a[href="/management/documents"]');
    }

    public function testManagerNavigationShowsOfficialManagementLinks(): void
    {
        $this->client->loginUser($this->manager()); $this->client->request('GET', '/announcements'); self::assertResponseIsSuccessful(); self::assertSelectorExists('nav a[href="/announcements"]'); self::assertSelectorExists('nav a[href="/documents"]'); self::assertSelectorTextContains('nav a[href="/management/announcements"]', 'Официални обяви'); self::assertSelectorTextContains('nav a[href="/management/documents"]', 'Документи');
    }

    public function testDashboardSummaryLinksToAnnouncementsAndShowsUnreadCount(): void
    {
        $this->client->loginUser($this->resident()); $this->client->request('GET', '/'); self::assertResponseIsSuccessful(); self::assertSelectorExists('[data-testid="resident-dashboard-summary"] a[href="/announcements"]'); self::assertSelectorTextSame('[data-testid="unread-announcement-count"]', '2');
    }

    private function entityManager(): EntityManagerInterface { $em = self::getContainer()->get('doctrine.orm.entity_manager'); self::assertInstanceOf(EntityManagerInterface::class, $em); return $em; }
    /** @param list<string> $roles */
    private function persistUser(EntityManagerInterface $em, string $email, array $roles = []): User { $person = new Person('Иван', 'Иванов', email: $email); $user = new User($person, $email, 'hash'); $user->setRoles($roles); $em->persist($person); $em->persist($user); return $user; }
    private function resident(): User { $resident = $this->entityManager()->find(User::class, $this->residentId); self::assertInstanceOf(User::class, $resident); return $resident; }
    private function manager(): User { $manager = $this->entityManager()->find(User::class, $this->managerId); self::assertInstanceOf(User::class, $manager); return $manager; }
}
