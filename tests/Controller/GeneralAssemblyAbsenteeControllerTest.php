<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AssemblyAbsenteeDeclaration;
use App\Entity\AssemblyAbsenteeWindow;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\AssemblyVote;
use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\AgendaItemStatus;
use App\Enum\AssemblyAbsenteeSignatureMode;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyDecisionKind;
use App\Enum\AssemblyPrincipalType;
use App\Enum\AssemblyVoteCastMode;
use App\Enum\AssemblyVoteDenominator;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Enum\MajorityComparison;
use App\Security\GeneralAssemblyAccessPolicy;
use App\Service\AssemblyAbsenteeVotingService;
use App\Service\AssemblyVotingService;
use App\Value\AssemblyMajorityRuleSnapshot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GeneralAssemblyAbsenteeControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $managerId;
    private int $residentId;
    private int $assemblyId;
    private int $itemId;
    private int $entryId;
    private int $evidenceId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = $this->entityManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $managerPerson = new Person('Мария', 'Управител', email: 'manager-absentee-http@example.com');
        $manager = new User($managerPerson, 'manager-absentee-http@example.com', 'hash');
        $manager->setRoles(['ROLE_MANAGER']);
        $residentPerson = new Person('Румен', 'Живущ', email: 'resident-absentee-http@example.com');
        $resident = new User($residentPerson, 'resident-absentee-http@example.com', 'hash');
        $owner = new Person('Анна', 'Собственик');
        $unit = new Unit('Ап. 1', idealParts: '100.0000');
        $assembly = GeneralAssembly::draft(
            'Общо събрание — неприсъствено гласуване',
            new DateTimeImmutable('2026-09-09T17:00:00Z'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-09'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Мария Управител',
            $manager,
            $manager,
            new DateTimeImmutable('2026-09-09T08:00:00Z'),
        );
        $item = $assembly->addAgendaItem(
            1,
            'Допустима неприсъствена точка',
            null,
            'Решение за неприсъствено гласуване.',
            AssemblyDecisionKind::ORDINARY,
            new AssemblyMajorityRuleSnapshot(
                'absentee-majority',
                AssemblyVoteDenominator::ELIGIBLE_ABSENTEE_UNIVERSE,
                '50',
                MajorityComparison::GREATER_THAN,
                'Правно основание за неприсъствено гласуване',
                '2026-09-09',
            ),
        );
        $entry = AssemblyElectorateEntry::snapshot(
            $assembly,
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
        $evidence = Document::record(
            DocumentCategory::OTHER,
            DocumentAccessLevel::GOVERNANCE,
            'Неприсъствена декларация — Анна Собственик',
            null,
            'declaration.pdf',
            'cccccccccccccccccccccccccccccccc.pdf',
            'application/pdf',
            128,
            $manager,
            new DateTimeImmutable('2026-09-09T18:30:00Z'),
        );

        foreach ([$managerPerson, $manager, $residentPerson, $resident, $owner, $unit, $assembly, $item, $entry, $evidence] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $assembly->convene($manager, new DateTimeImmutable('2026-09-09T16:00:00Z'));
        $assembly->start($manager, new DateTimeImmutable('2026-09-09T17:00:00Z'));
        $item->open('Финално решение за неприсъствено гласуване.', new DateTimeImmutable('2026-09-09T17:05:00Z'));
        $assembly->close($manager, new DateTimeImmutable('2026-09-09T18:00:00Z'));
        $em->flush();

        self::assertNotNull($manager->getId());
        self::assertNotNull($resident->getId());
        self::assertNotNull($assembly->getId());
        self::assertNotNull($item->getId());
        self::assertNotNull($entry->getId());
        self::assertNotNull($evidence->getId());
        $this->managerId = $manager->getId();
        $this->residentId = $resident->getId();
        $this->assemblyId = $assembly->getId();
        $this->itemId = $item->getId();
        $this->entryId = $entry->getId();
        $this->evidenceId = $evidence->getId();
    }

    public function testManagerCanOpenAbsenteeWindowFromWorkbenchAndCsrfIsRequired(): void
    {
        $this->client->loginUser($this->user($this->managerId));
        $crawler = $this->client->request('GET', $this->workbenchUrl());
        self::assertResponseIsSuccessful();
        $button = $crawler->selectButton('assembly_absentee_open_'.$this->assemblyId);
        self::assertCount(1, $button);

        $this->client->request('POST', $this->openUrl(), [
            '_token' => 'invalid',
            'agenda_item_ids' => [(string) $this->itemId],
            'deadline_at' => '2026-09-10T12:00',
            'legal_basis' => 'Правно основание за неприсъствено гласуване',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->entityManager()->getRepository(AssemblyAbsenteeWindow::class)->findAll());

        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($this->user($this->managerId));
        $crawler = $this->client->request('GET', $this->workbenchUrl());
        self::assertResponseIsSuccessful();
        $button = $crawler->selectButton('assembly_absentee_open_'.$this->assemblyId);
        self::assertCount(1, $button);
        $token = (string) $button->ancestors()->filter('form')->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', $this->openUrl(), [
            '_token' => $token,
            'agenda_item_ids' => [(string) $this->itemId],
            'deadline_at' => '2026-09-10T12:00',
            'legal_basis' => 'Правно основание за неприсъствено гласуване',
        ]);
        self::assertResponseRedirects($this->workbenchUrl());

        $window = $this->entityManager()->getRepository(AssemblyAbsenteeWindow::class)->findOneBy([]);
        self::assertInstanceOf(AssemblyAbsenteeWindow::class, $window);
        $item = $this->entityManager()->find(\App\Entity\AssemblyAgendaItem::class, $this->itemId);
        self::assertInstanceOf(\App\Entity\AssemblyAgendaItem::class, $item);
        self::assertSame(AgendaItemStatus::ABSENTEE_WINDOW, $item->getStatus());
    }

    public function testManagerCanRegisterEvidenceBackedDeclaration(): void
    {
        $window = $this->openWindowDirectly(
            new DateTimeImmutable('2026-09-09T18:10:00Z'),
            new DateTimeImmutable('2026-09-10T09:00:00Z'),
        );
        self::assertNotNull($window->getId());

        $this->client->loginUser($this->user($this->managerId));
        $crawler = $this->client->request('GET', $this->workbenchUrl());
        self::assertResponseIsSuccessful();
        $button = $crawler->selectButton('assembly_absentee_declaration_'.$window->getId());
        self::assertCount(1, $button);
        $token = (string) $button->ancestors()->filter('form')->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', $this->declarationUrl($window->getId()), [
            '_token' => $token,
            'electorate_entry_id' => (string) $this->entryId,
            'evidence_document_id' => (string) $this->evidenceId,
            'signature_mode' => AssemblyAbsenteeSignatureMode::HAND_SIGNED->value,
            'choices' => [(string) $this->itemId => 'for'],
            'notes' => 'Представена подписана декларация.',
        ]);
        self::assertResponseRedirects($this->workbenchUrl());

        self::assertCount(1, $this->entityManager()->getRepository(AssemblyAbsenteeDeclaration::class)->findAll());
        $votes = $this->entityManager()->getRepository(AssemblyVote::class)->findAll();
        self::assertCount(1, $votes);
        self::assertSame(AssemblyVoteCastMode::ABSENTEE, $votes[0]->getCastMode());
    }

    public function testDeclarationAndCloseRequireValidCsrfWithoutMutation(): void
    {
        $window = $this->openWindowDirectly(
            new DateTimeImmutable('2026-09-09T18:10:00Z'),
            new DateTimeImmutable('2026-09-10T09:00:00Z'),
        );
        self::assertNotNull($window->getId());

        $this->client->loginUser($this->user($this->managerId));
        $this->client->request('POST', $this->declarationUrl($window->getId()), [
            '_token' => 'invalid',
            'electorate_entry_id' => (string) $this->entryId,
            'evidence_document_id' => (string) $this->evidenceId,
            'signature_mode' => AssemblyAbsenteeSignatureMode::HAND_SIGNED->value,
            'choices' => [(string) $this->itemId => 'for'],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->entityManager()->getRepository(AssemblyAbsenteeDeclaration::class)->findAll());
        self::assertCount(0, $this->entityManager()->getRepository(AssemblyVote::class)->findAll());

        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($this->user($this->managerId));
        $this->client->request('POST', $this->closeUrl($window->getId()), ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $reloaded = $this->entityManager()->find(AssemblyAbsenteeWindow::class, $window->getId());
        self::assertInstanceOf(AssemblyAbsenteeWindow::class, $reloaded);
        self::assertNull($reloaded->getClosedAt());
    }

    public function testManagerCanCloseExpiredWindowAndResidentCannotMutateIt(): void
    {
        $window = $this->openWindowDirectly(
            new DateTimeImmutable('2026-09-09T18:10:00Z'),
            new DateTimeImmutable('2026-09-09T20:00:00Z'),
        );
        self::assertNotNull($window->getId());

        $this->client->loginUser($this->user($this->residentId));
        $this->client->request('POST', $this->closeUrl($window->getId()), ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($this->user($this->managerId));
        $crawler = $this->client->request('GET', $this->workbenchUrl());
        $button = $crawler->selectButton('assembly_absentee_close_'.$window->getId());
        self::assertCount(1, $button);
        $form = $button->form();
        $this->client->submit($form);
        self::assertResponseRedirects($this->workbenchUrl());

        $reloaded = $this->entityManager()->find(AssemblyAbsenteeWindow::class, $window->getId());
        self::assertInstanceOf(AssemblyAbsenteeWindow::class, $reloaded);
        self::assertNotNull($reloaded->getClosedAt());
    }

    private function openWindowDirectly(DateTimeImmutable $openedAt, DateTimeImmutable $deadlineAt): AssemblyAbsenteeWindow
    {
        $assembly = $this->entityManager()->find(GeneralAssembly::class, $this->assemblyId);
        $item = $this->entityManager()->find(\App\Entity\AssemblyAgendaItem::class, $this->itemId);
        self::assertInstanceOf(GeneralAssembly::class, $assembly);
        self::assertInstanceOf(\App\Entity\AssemblyAgendaItem::class, $item);

        return $this->absenteeService()->openWindow(
            $this->user($this->managerId),
            $assembly,
            [$item],
            $openedAt,
            $deadlineAt,
            'Правно основание за неприсъствено гласуване',
        );
    }

    private function absenteeService(): AssemblyAbsenteeVotingService
    {
        $policy = self::getContainer()->get(GeneralAssemblyAccessPolicy::class);
        $voting = self::getContainer()->get(AssemblyVotingService::class);
        self::assertInstanceOf(GeneralAssemblyAccessPolicy::class, $policy);
        self::assertInstanceOf(AssemblyVotingService::class, $voting);

        return new AssemblyAbsenteeVotingService($this->entityManager(), $policy, $voting);
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

    private function workbenchUrl(): string
    {
        return '/management/assembly/'.$this->assemblyId.'/workbench';
    }

    private function openUrl(): string
    {
        return '/management/assembly/'.$this->assemblyId.'/absentee/open';
    }

    private function declarationUrl(int $windowId): string
    {
        return '/management/assembly/'.$this->assemblyId.'/absentee/'.$windowId.'/declaration';
    }

    private function closeUrl(int $windowId): string
    {
        return '/management/assembly/'.$this->assemblyId.'/absentee/'.$windowId.'/close';
    }
}
