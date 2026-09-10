<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AssemblyAgendaItem;
use App\Entity\AssemblyAttendance;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\AssemblyInvitationPosting;
use App\Entity\AssemblyResolution;
use App\Entity\AssemblyVote;
use App\Entity\AssemblyVoteCorrection;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\AgendaItemStatus;
use App\Enum\AssemblyAttendanceMode;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyDecisionKind;
use App\Enum\AssemblyPrincipalType;
use App\Enum\AssemblyVoteChoice;
use App\Enum\AssemblyVoteDenominator;
use App\Enum\MajorityComparison;
use App\Value\AssemblyMajorityRuleSnapshot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GeneralAssemblyMutationCsrfTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $managerId;
    private int $draftAssemblyId;
    private int $postingAssemblyId;
    private int $votingAssemblyId;
    private int $plannedItemId;
    private int $openItemId;
    private int $entryId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = $this->entityManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $managerPerson = new Person('Мария', 'Управител', email: 'csrf-matrix@example.com');
        $manager = new User($managerPerson, 'csrf-matrix@example.com', 'hash');
        $manager->setRoles(['ROLE_MANAGER']);
        $em->persist($managerPerson);
        $em->persist($manager);

        $draft = $this->newAssembly('Редактируема чернова', $manager, '2026-09-21T15:00:00Z');
        $posting = $this->newAssembly('Свикано за поставяне', $manager, '2026-09-10T08:00:00Z');
        $voting = $this->newAssembly('Активно гласуване', $manager, '2026-09-10T05:00:00Z');

        $planned = $voting->addAgendaItem(1, 'Планирана точка', null, 'Проект 1', AssemblyDecisionKind::ORDINARY, $this->rule());
        $open = $voting->addAgendaItem(2, 'Отворена точка', null, 'Проект 2', AssemblyDecisionKind::ORDINARY, $this->rule());
        $owner = new Person('Анна', 'Собственик');
        $unit = new Unit('Ап. 1', idealParts: '100.0000');
        $entry = AssemblyElectorateEntry::snapshot(
            $voting,
            $unit,
            null,
            'Ап. 1',
            AssemblyPrincipalType::PERSON,
            $owner,
            'Анна Собственик',
            null,
            'owner',
            '100',
            '100',
            '100',
            true,
            null,
            new DateTimeImmutable('2026-09-09T09:00:00Z'),
        );

        foreach ([$draft, $posting, $voting, $planned, $open, $owner, $unit, $entry] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $posting->convene($manager, new DateTimeImmutable('2026-09-10T04:00:00Z'));
        $voting->convene($manager, new DateTimeImmutable('2026-09-10T04:00:00Z'));
        $voting->start($manager, new DateTimeImmutable('2026-09-10T05:00:00Z'));
        $attendance = AssemblyAttendance::register(
            $voting,
            $entry,
            AssemblyAttendanceMode::IN_PERSON,
            $manager,
            new DateTimeImmutable('2026-09-10T05:00:00Z'),
        );
        $open->open('Финален текст 2', new DateTimeImmutable('2026-09-10T05:01:00Z'));
        $em->persist($attendance);
        $em->flush();

        foreach ([$manager, $draft, $posting, $voting, $planned, $open, $entry] as $entity) {
            self::assertNotNull($entity->getId());
        }
        $this->managerId = $manager->getId();
        $this->draftAssemblyId = $draft->getId();
        $this->postingAssemblyId = $posting->getId();
        $this->votingAssemblyId = $voting->getId();
        $this->plannedItemId = $planned->getId();
        $this->openItemId = $open->getId();
        $this->entryId = $entry->getId();
    }

    public function testMissingManagementPostRoutesRejectInvalidCsrfWithoutMutation(): void
    {
        $this->client->loginUser($this->user());

        $this->client->request('POST', '/management/assembly/'.$this->draftAssemblyId.'/edit', [
            '_token' => 'invalid',
            'title' => 'Не трябва да се запише',
            'scheduled_at' => '2026-09-21T18:00',
            'reference_date' => '2026-09-09',
            'place' => 'Друго място',
            'convening_basis' => AssemblyConveningBasis::MANAGER_OR_BOARD->value,
            'initiator_display_name' => 'Друг',
        ]);
        self::assertResponseStatusCodeSame(403);
        $draft = $this->entityManager()->find(GeneralAssembly::class, $this->draftAssemblyId);
        self::assertInstanceOf(GeneralAssembly::class, $draft);
        self::assertSame('Редактируема чернова', $draft->getTitle());

        $this->client->request('POST', '/management/assembly/'.$this->postingAssemblyId.'/posting', [
            '_token' => 'invalid',
            'posting_place' => 'До входната врата',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->entityManager()->getRepository(AssemblyInvitationPosting::class)->findAll());

        $this->client->request('POST', $this->openUrl(), [
            '_token' => 'invalid',
            'final_resolution_text' => 'Не трябва да се отвори',
        ]);
        self::assertResponseStatusCodeSame(403);
        $planned = $this->entityManager()->find(AssemblyAgendaItem::class, $this->plannedItemId);
        self::assertInstanceOf(AssemblyAgendaItem::class, $planned);
        self::assertSame(AgendaItemStatus::PLANNED, $planned->getStatus());

        $this->client->request('POST', $this->voteUrl(), [
            '_token' => 'invalid',
            'choice' => AssemblyVoteChoice::FOR->value,
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->entityManager()->getRepository(AssemblyVote::class)->findAll());

        $open = $this->entityManager()->find(AssemblyAgendaItem::class, $this->openItemId);
        $entry = $this->entityManager()->find(AssemblyElectorateEntry::class, $this->entryId);
        self::assertInstanceOf(AssemblyAgendaItem::class, $open);
        self::assertInstanceOf(AssemblyElectorateEntry::class, $entry);
        $vote = AssemblyVote::record(
            $open,
            $entry,
            AssemblyVoteChoice::FOR,
            '100',
            $this->user(),
            new DateTimeImmutable('2026-09-10T05:02:00Z'),
        );
        $this->entityManager()->persist($vote);
        $this->entityManager()->flush();
        self::assertNotNull($vote->getId());

        $this->client->request('POST', '/management/assembly/'.$this->votingAssemblyId.'/vote/'.$vote->getId().'/correct', [
            '_token' => 'invalid',
            'choice' => AssemblyVoteChoice::AGAINST->value,
            'reason' => 'Не трябва да се запише',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(AssemblyVoteChoice::FOR, $vote->getChoice());
        self::assertCount(0, $this->entityManager()->getRepository(AssemblyVoteCorrection::class)->findAll());

        $this->client->request('POST', $this->resolveUrl(), ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(AgendaItemStatus::OPEN, $open->getStatus());
        self::assertCount(0, $this->entityManager()->getRepository(AssemblyResolution::class)->findAll());
    }

    public function testMissingManagementPostRoutesAcceptTheirOwnValidCsrfTokens(): void
    {
        $this->client->loginUser($this->user());

        $crawler = $this->client->request('GET', '/management/assembly/'.$this->draftAssemblyId.'/edit');
        $form = $crawler->selectButton('assembly_submit')->form([
            'title' => 'Редактирана чернова',
            'scheduled_at' => '2026-09-21T18:00',
            'reference_date' => '2026-09-09',
            'place' => 'Вход А',
            'convening_basis' => AssemblyConveningBasis::MANAGER_OR_BOARD->value,
            'initiator_display_name' => 'Мария Управител',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/management/assemblies');
        $draft = $this->entityManager()->find(GeneralAssembly::class, $this->draftAssemblyId);
        self::assertInstanceOf(GeneralAssembly::class, $draft);
        self::assertSame('Редактирана чернова', $draft->getTitle());

        $crawler = $this->client->request('GET', '/management/assemblies');
        $form = $crawler->filter('form[action="/management/assembly/'.$this->postingAssemblyId.'/posting"]')->form([
            'posting_place' => 'До входната врата',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/management/assemblies');
        self::assertCount(1, $this->entityManager()->getRepository(AssemblyInvitationPosting::class)->findBy([
            'assembly' => $this->entityManager()->find(GeneralAssembly::class, $this->postingAssemblyId),
        ]));

        $crawler = $this->client->request('GET', $this->workbenchUrl());
        $form = $crawler->filter('form[action="'.$this->openUrl().'"]')->form([
            'final_resolution_text' => 'Финален текст 1',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects($this->workbenchUrl());

        $crawler = $this->client->request('GET', $this->workbenchUrl());
        $form = $crawler->filter('form[action="'.$this->voteUrl().'"]')->form([
            'choice' => AssemblyVoteChoice::FOR->value,
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects($this->workbenchUrl());

        $vote = $this->entityManager()->getRepository(AssemblyVote::class)->findOneBy([
            'agendaItem' => $this->entityManager()->find(AssemblyAgendaItem::class, $this->openItemId),
            'electorateEntry' => $this->entityManager()->find(AssemblyElectorateEntry::class, $this->entryId),
        ]);
        self::assertInstanceOf(AssemblyVote::class, $vote);

        $crawler = $this->client->request('GET', $this->workbenchUrl());
        $correctUrl = '/management/assembly/'.$this->votingAssemblyId.'/vote/'.$vote->getId().'/correct';
        $form = $crawler->filter('form[action="'.$correctUrl.'"]')->form([
            'choice' => AssemblyVoteChoice::AGAINST->value,
            'reason' => 'Корекция с валиден CSRF',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects($this->workbenchUrl());
        self::assertCount(1, $this->entityManager()->getRepository(AssemblyVoteCorrection::class)->findAll());

        $crawler = $this->client->request('GET', $this->workbenchUrl());
        $form = $crawler->filter('form[action="'.$this->resolveUrl().'"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects($this->workbenchUrl());
        self::assertCount(1, $this->entityManager()->getRepository(AssemblyResolution::class)->findAll());
    }

    private function newAssembly(string $title, User $manager, string $scheduledAt): GeneralAssembly
    {
        return GeneralAssembly::draft(
            $title,
            new DateTimeImmutable($scheduledAt),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-09'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Мария Управител',
            $manager,
            $manager,
            new DateTimeImmutable('2026-09-09T08:00:00Z'),
        );
    }

    private function rule(): AssemblyMajorityRuleSnapshot
    {
        return new AssemblyMajorityRuleSnapshot(
            'csrf-matrix-majority',
            AssemblyVoteDenominator::REPRESENTED_AT_MEETING,
            '50',
            MajorityComparison::GREATER_THAN,
            'ЗУЕС — тестово мнозинство',
            '2026-09-09',
        );
    }

    private function user(): User
    {
        $user = $this->entityManager()->find(User::class, $this->managerId);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function workbenchUrl(): string
    {
        return '/management/assembly/'.$this->votingAssemblyId.'/workbench';
    }

    private function openUrl(): string
    {
        return '/management/assembly/'.$this->votingAssemblyId.'/item/'.$this->plannedItemId.'/open';
    }

    private function voteUrl(): string
    {
        return '/management/assembly/'.$this->votingAssemblyId.'/item/'.$this->openItemId.'/vote/'.$this->entryId;
    }

    private function resolveUrl(): string
    {
        return '/management/assembly/'.$this->votingAssemblyId.'/item/'.$this->openItemId.'/resolve';
    }
}
