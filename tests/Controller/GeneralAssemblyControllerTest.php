<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AssemblyResolution;
use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyDecisionKind;
use App\Enum\AssemblyResolutionResult;
use App\Enum\AssemblyVoteDenominator;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Enum\MajorityComparison;
use App\Value\AssemblyMajorityRuleSnapshot;
use App\Value\AssemblyResolutionCalculation;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GeneralAssemblyControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $residentId;
    private int $inactiveId;
    private int $draftId;
    private int $convenedId;
    private int $inProgressId;
    private int $closedId;
    private int $finalizedId;
    private int $invitationId;
    private int $minutesId;
    private int $governanceId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = $this->entityManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->clearStorage();

        $resident = $this->persistUser($em, 'resident-assembly@example.com');
        $inactive = $this->persistUser($em, 'inactive-assembly@example.com');
        $inactive->deactivate();
        $manager = $this->persistUser($em, 'manager-assembly@example.com', ['ROLE_MANAGER']);
        $em->flush();

        $draft = $this->assembly('Чернова ОС', $manager, '2026-09-12T17:00:00Z');
        $em->persist($draft);

        $invitation = $this->document(
            $manager,
            DocumentCategory::MEETING_INVITATION,
            DocumentAccessLevel::RESIDENTS,
            'Покана за ОС',
            'meeting-invitation.pdf',
            str_repeat('1', 32).'.pdf',
        );
        $em->persist($invitation);
        $convened = $this->assembly('Свикано ОС', $manager, '2026-09-13T17:00:00Z');
        $convened->linkInvitation($invitation);
        $convened->addAgendaItem(1, 'Ремонт на покрива', null, 'Да се извърши ремонт.', AssemblyDecisionKind::ORDINARY, $this->rule());
        $em->persist($convened);
        foreach ($convened->getAgendaItems() as $item) {
            $em->persist($item);
        }
        $convened->convene($manager, new DateTimeImmutable('2026-09-13T12:00:00Z'));

        $inProgress = $this->assembly('Текущо ОС', $manager, '2026-09-14T17:00:00Z');
        $inProgressItem = $inProgress->addAgendaItem(1, 'Избор на изпълнител', null, 'Да се избере изпълнител.', AssemblyDecisionKind::ORDINARY, $this->rule());
        $em->persist($inProgress);
        $em->persist($inProgressItem);
        $inProgress->convene($manager, new DateTimeImmutable('2026-09-14T12:00:00Z'));
        $inProgress->start($manager, new DateTimeImmutable('2026-09-14T17:00:00Z'));
        $inProgressItem->open('Да се избере изпълнител А.', new DateTimeImmutable('2026-09-14T17:05:00Z'));

        [$closed, $closedResolution] = $this->resolvedAssembly('Приключило ОС', $manager, '2026-09-15T17:00:00Z');
        $em->persist($closed);
        foreach ($closed->getAgendaItems() as $item) {
            $em->persist($item);
        }
        $em->persist($closedResolution);

        [$finalized, $finalizedResolution] = $this->resolvedAssembly('Финализирано ОС', $manager, '2026-09-16T17:00:00Z');
        $minutes = $this->document(
            $manager,
            DocumentCategory::MEETING_MINUTES,
            DocumentAccessLevel::RESIDENTS,
            'Протокол от ОС',
            'meeting-minutes.pdf',
            str_repeat('2', 32).'.pdf',
        );
        $em->persist($minutes);
        $finalized->setMinutesMetadata('Иван Председател', 'Елена Протоколчик', 'Официален протокол.');
        $finalized->finalizeMinutes(
            $minutes,
            $manager,
            new DateTimeImmutable('2026-09-16T19:00:00Z'),
            new DateTimeImmutable('2026-09-23'),
        );
        $em->persist($finalized);
        foreach ($finalized->getAgendaItems() as $item) {
            $em->persist($item);
        }
        $em->persist($finalizedResolution);

        $governance = $this->document(
            $manager,
            DocumentCategory::OTHER,
            DocumentAccessLevel::GOVERNANCE,
            'Служебно доказателство',
            'governance-evidence.pdf',
            str_repeat('3', 32).'.pdf',
        );
        $em->persist($governance);
        $em->flush();

        foreach ([$invitation, $minutes, $governance] as $document) {
            file_put_contents($this->storageDirectory().'/'.$document->getStorageName(), '%PDF-1.4 test');
        }

        self::assertNotNull($resident->getId());
        self::assertNotNull($inactive->getId());
        self::assertNotNull($draft->getId());
        self::assertNotNull($convened->getId());
        self::assertNotNull($inProgress->getId());
        self::assertNotNull($closed->getId());
        self::assertNotNull($finalized->getId());
        self::assertNotNull($invitation->getId());
        self::assertNotNull($minutes->getId());
        self::assertNotNull($governance->getId());

        $this->residentId = $resident->getId();
        $this->inactiveId = $inactive->getId();
        $this->draftId = $draft->getId();
        $this->convenedId = $convened->getId();
        $this->inProgressId = $inProgress->getId();
        $this->closedId = $closed->getId();
        $this->finalizedId = $finalized->getId();
        $this->invitationId = $invitation->getId();
        $this->minutesId = $minutes->getId();
        $this->governanceId = $governance->getId();
    }

    protected function tearDown(): void
    {
        $this->clearStorage();
        parent::tearDown();
    }

    public function testAnonymousIsRedirectedAndDraftIsNeverResidentVisible(): void
    {
        $this->client->request('GET', '/assemblies');
        self::assertResponseRedirects('/login');

        $this->client->loginUser($this->user($this->residentId));
        $this->client->request('GET', '/assembly/'.$this->draftId);
        self::assertResponseStatusCodeSame(404);
    }

    public function testResidentListsEveryNonDraftMeetingNewestFirst(): void
    {
        $this->client->loginUser($this->user($this->residentId));
        $this->client->request('GET', '/assemblies');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Чернова ОС', $content);
        foreach (['Свикано ОС', 'Текущо ОС', 'Приключило ОС', 'Финализирано ОС'] as $title) {
            self::assertStringContainsString($title, $content);
        }
        self::assertLessThan(strpos($content, 'Приключило ОС'), strpos($content, 'Финализирано ОС'));
        self::assertLessThan(strpos($content, 'Текущо ОС'), strpos($content, 'Приключило ОС'));
        self::assertLessThan(strpos($content, 'Свикано ОС'), strpos($content, 'Текущо ОС'));
    }

    public function testResidentDetailIsOfficialReadOnlyAndShowsInvitationAndPersistedResult(): void
    {
        $this->client->loginUser($this->user($this->residentId));

        $this->client->request('GET', '/assembly/'.$this->convenedId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Официално общо събрание');
        self::assertSelectorTextContains('body', 'Ремонт на покрива');
        self::assertSelectorExists('a[href="/document/'.$this->invitationId.'/download"]');
        self::assertSelectorNotExists('form[action*="/vote"]');
        self::assertSelectorTextNotContains('body', 'Харесва');
        self::assertSelectorTextNotContains('body', 'Коментар');

        $this->client->request('GET', '/assembly/'.$this->closedId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Резултат: accepted');
        self::assertSelectorTextContains('body', 'Решението е прието.');
    }

    public function testFinalizedMinutesPageIsAvailableOnlyAfterFinalization(): void
    {
        $this->client->loginUser($this->user($this->residentId));

        $this->client->request('GET', '/assembly/'.$this->closedId.'/minutes');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/assembly/'.$this->finalizedId.'/minutes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Протокол от общо събрание');
        self::assertSelectorExists('a[href="/document/'.$this->minutesId.'/download"]');
        self::assertSelectorTextContains('body', 'Иван Председател');
        self::assertSelectorNotExists('form');
    }

    public function testInactiveUserIsDeniedEvenWhenInjectedByTestLogin(): void
    {
        $this->client->loginUser($this->user($this->inactiveId));
        $this->client->request('GET', '/assemblies');

        self::assertResponseStatusCodeSame(403);
    }

    public function testResidentCanDownloadOfficialResidentDocumentsButNotGovernanceEvidence(): void
    {
        $this->client->loginUser($this->user($this->residentId));

        foreach ([$this->invitationId, $this->minutesId] as $id) {
            $this->client->request('GET', '/document/'.$id.'/download');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('content-type', 'application/pdf');
        }

        $this->client->request('GET', '/document/'.$this->governanceId.'/download');
        self::assertResponseStatusCodeSame(404);
    }

    public function testThereIsNoResidentFormalVoteEndpoint(): void
    {
        $this->client->loginUser($this->user($this->residentId));
        $this->client->request('POST', '/assembly/'.$this->inProgressId.'/vote', []);

        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{GeneralAssembly, AssemblyResolution} */
    private function resolvedAssembly(string $title, User $manager, string $scheduledAt): array
    {
        $assembly = $this->assembly($title, $manager, $scheduledAt);
        $item = $assembly->addAgendaItem(1, 'Избор на изпълнител', null, 'Да се избере изпълнител.', AssemblyDecisionKind::ORDINARY, $this->rule());
        $scheduled = new DateTimeImmutable($scheduledAt);
        $assembly->convene($manager, $scheduled->modify('-5 hours'));
        $assembly->start($manager, $scheduled);
        $item->open('Да се избере изпълнител А.', $scheduled->modify('+5 minutes'));
        $resolution = AssemblyResolution::record(
            $item,
            new AssemblyResolutionCalculation(
                '60',
                '10',
                '5',
                '75',
                '37.5',
                AssemblyResolutionResult::ACCEPTED,
                'Решението е прието.',
            ),
            $manager,
            $scheduled->modify('+45 minutes'),
        );
        $item->resolve($scheduled->modify('+45 minutes'));
        $assembly->close($manager, $scheduled->modify('+1 hour'));

        return [$assembly, $resolution];
    }

    private function assembly(string $title, User $manager, string $scheduledAt): GeneralAssembly
    {
        $scheduled = new DateTimeImmutable($scheduledAt);

        return GeneralAssembly::draft(
            $title,
            $scheduled,
            'Europe/Sofia',
            new DateTimeImmutable($scheduled->format('Y-m-d')),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Мария Управител',
            $manager,
            $manager,
            $scheduled->modify('-9 hours'),
        );
    }

    private function rule(): AssemblyMajorityRuleSnapshot
    {
        return new AssemblyMajorityRuleSnapshot(
            'resident-view-rule',
            AssemblyVoteDenominator::REPRESENTED_AT_MEETING,
            '50',
            MajorityComparison::GREATER_THAN,
            'ЗУЕС — тестово основание',
            '2026-09-10',
        );
    }

    private function document(
        User $manager,
        DocumentCategory $category,
        DocumentAccessLevel $access,
        string $title,
        string $originalName,
        string $storageName,
    ): Document {
        return Document::record(
            $category,
            $access,
            $title,
            'Официален документ.',
            $originalName,
            $storageName,
            'application/pdf',
            13,
            $manager,
            new DateTimeImmutable('2026-09-10T08:00:00Z'),
        );
    }

    /** @param list<string> $roles */
    private function persistUser(EntityManagerInterface $em, string $email, array $roles = []): User
    {
        $person = new Person('Иван', 'Иванов', email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);
        $em->persist($person);
        $em->persist($user);

        return $user;
    }

    private function user(int $id): User
    {
        $user = $this->entityManager()->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
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
