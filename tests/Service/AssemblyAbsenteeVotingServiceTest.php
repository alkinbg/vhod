<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssemblyAbsenteeDeclaration;
use App\Entity\AssemblyAbsenteeWindow;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\AssemblyResolution;
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
use App\Enum\AssemblyResolutionResult;
use App\Enum\AssemblyVoteCastMode;
use App\Enum\AssemblyVoteChoice;
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
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AssemblyAbsenteeVotingServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AssemblyAbsenteeVotingService $service;
    private User $manager;
    private GeneralAssembly $assembly;
    private AssemblyElectorateEntry $entry;
    private Document $evidence;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $accessPolicy = self::getContainer()->get(GeneralAssemblyAccessPolicy::class);
        self::assertInstanceOf(GeneralAssemblyAccessPolicy::class, $accessPolicy);
        $votingService = self::getContainer()->get(AssemblyVotingService::class);
        self::assertInstanceOf(AssemblyVotingService::class, $votingService);
        $this->service = new AssemblyAbsenteeVotingService($em, $accessPolicy, $votingService);

        $managerPerson = new Person('Мария', 'Управител', email: 'manager-absentee@example.com');
        $this->manager = new User($managerPerson, 'manager-absentee@example.com', 'hash');
        $this->manager->setRoles(['ROLE_MANAGER']);
        $owner = new Person('Анна', 'Собственик');
        $unit = new Unit('Ап. 1', idealParts: '100.0000');

        $this->assembly = GeneralAssembly::draft(
            'Общо събрание',
            new DateTimeImmutable('2026-09-09T14:00:00Z'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-09'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Мария Управител',
            $this->manager,
            $this->manager,
            new DateTimeImmutable('2026-09-09T08:00:00Z'),
        );
        $this->entry = AssemblyElectorateEntry::snapshot(
            $this->assembly,
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
        $this->evidence = Document::record(
            DocumentCategory::OTHER,
            DocumentAccessLevel::GOVERNANCE,
            'Неприсъствена декларация',
            null,
            'declaration.pdf',
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf',
            'application/pdf',
            128,
            $this->manager,
            new DateTimeImmutable('2026-09-09T16:00:00Z'),
        );

        foreach ([$managerPerson, $this->manager, $owner, $unit, $this->assembly, $this->entry, $this->evidence] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
    }

    public function testWindowRequiresClosedMeetingAndExplicitAbsenteeDenominator(): void
    {
        $eligible = $this->eligibleItem();
        $ineligible = $this->assembly->addAgendaItem(
            2,
            'Обикновена точка',
            null,
            'Обикновено решение.',
            AssemblyDecisionKind::ORDINARY,
            new AssemblyMajorityRuleSnapshot(
                'represented-majority',
                AssemblyVoteDenominator::REPRESENTED_AT_MEETING,
                '50',
                MajorityComparison::GREATER_THAN,
                'ЗУЕС — приложимо мнозинство',
                '2026-09-09',
            ),
        );
        $this->em->persist($eligible);
        $this->em->persist($ineligible);
        $this->em->flush();

        $this->assembly->convene($this->manager, new DateTimeImmutable('2026-09-09T12:00:00Z'));
        $this->assembly->start($this->manager, new DateTimeImmutable('2026-09-09T14:00:00Z'));
        $eligible->open('Финално неприсъствено решение.', new DateTimeImmutable('2026-09-09T14:05:00Z'));
        $ineligible->open('Финално обикновено решение.', new DateTimeImmutable('2026-09-09T14:06:00Z'));
        $this->em->flush();

        try {
            $this->service->openWindow(
                $this->manager,
                $this->assembly,
                [$eligible],
                new DateTimeImmutable('2026-09-09T15:00:00Z'),
                new DateTimeImmutable('2026-09-10T15:00:00Z'),
                'Правно основание',
            );
            self::fail('Absentee window must require a CLOSED meeting.');
        } catch (DomainException) {
            self::assertSame(AgendaItemStatus::OPEN, $eligible->getStatus());
        }

        $this->assembly->close($this->manager, new DateTimeImmutable('2026-09-09T15:00:00Z'));
        $this->em->flush();

        $this->expectException(DomainException::class);
        $this->service->openWindow(
            $this->manager,
            $this->assembly,
            [$ineligible],
            new DateTimeImmutable('2026-09-09T15:01:00Z'),
            new DateTimeImmutable('2026-09-10T15:00:00Z'),
            'Правно основание',
        );
    }

    public function testDeclarationCreatesOneEvidenceBackedFormalAbsenteeVoteAndRejectsDuplicates(): void
    {
        [$window, $item] = $this->openWindow();
        self::assertNotNull($item->getId());

        $declaration = $this->service->registerDeclaration(
            $this->manager,
            $window,
            $this->entry,
            $this->evidence,
            AssemblyAbsenteeSignatureMode::ELECTRONIC_DECLARATION_RECORDED,
            [$item->getId() => AssemblyVoteChoice::FOR],
            new DateTimeImmutable('2026-09-09T16:00:00Z'),
            'Електронният режим описва само вида на представеното доказателство.',
        );

        self::assertInstanceOf(AssemblyAbsenteeDeclaration::class, $declaration);
        self::assertSame($this->evidence, $declaration->getEvidenceDocument());
        $votes = $this->em->getRepository(AssemblyVote::class)->findAll();
        self::assertCount(1, $votes);
        self::assertSame(AssemblyVoteCastMode::ABSENTEE, $votes[0]->getCastMode());
        self::assertSame(AssemblyVoteChoice::FOR, $votes[0]->getChoice());
        self::assertSame('100.00000000', $votes[0]->getWeightIdealPartsPercent());

        $this->expectException(DomainException::class);
        $this->service->registerDeclaration(
            $this->manager,
            $window,
            $this->entry,
            $this->evidence,
            AssemblyAbsenteeSignatureMode::HAND_SIGNED,
            [$item->getId() => AssemblyVoteChoice::AGAINST],
            new DateTimeImmutable('2026-09-09T17:00:00Z'),
        );
    }

    public function testDeadlineGovernanceEvidenceAndCloseWindowAreEnforced(): void
    {
        [$window, $item] = $this->openWindow(deadline: '2026-09-09T16:30:00Z');
        self::assertNotNull($item->getId());

        $residentEvidence = Document::record(
            DocumentCategory::OTHER,
            DocumentAccessLevel::RESIDENTS,
            'Неподходящо доказателство',
            null,
            'resident.pdf',
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.pdf',
            'application/pdf',
            128,
            $this->manager,
            new DateTimeImmutable('2026-09-09T16:00:00Z'),
        );
        $this->em->persist($residentEvidence);
        $this->em->flush();

        try {
            $this->service->registerDeclaration(
                $this->manager,
                $window,
                $this->entry,
                $residentEvidence,
                AssemblyAbsenteeSignatureMode::HAND_SIGNED,
                [$item->getId() => AssemblyVoteChoice::FOR],
                new DateTimeImmutable('2026-09-09T16:00:00Z'),
            );
            self::fail('Resident-visible evidence must not be accepted for an absentee declaration.');
        } catch (DomainException) {
            self::assertCount(0, $this->em->getRepository(AssemblyAbsenteeDeclaration::class)->findAll());
        }

        try {
            $this->service->registerDeclaration(
                $this->manager,
                $window,
                $this->entry,
                $this->evidence,
                AssemblyAbsenteeSignatureMode::HAND_SIGNED,
                [$item->getId() => AssemblyVoteChoice::FOR],
                new DateTimeImmutable('2026-09-09T16:31:00Z'),
            );
            self::fail('Declaration after the deadline must be rejected.');
        } catch (DomainException) {
            self::assertCount(0, $this->em->getRepository(AssemblyAbsenteeDeclaration::class)->findAll());
        }

        $this->service->registerDeclaration(
            $this->manager,
            $window,
            $this->entry,
            $this->evidence,
            AssemblyAbsenteeSignatureMode::HAND_SIGNED,
            [$item->getId() => AssemblyVoteChoice::FOR],
            new DateTimeImmutable('2026-09-09T16:15:00Z'),
        );
        $this->service->closeWindow($this->manager, $window, new DateTimeImmutable('2026-09-09T16:30:00Z'));

        self::assertNotNull($window->getClosedAt());
        self::assertSame(AgendaItemStatus::RESOLVED, $item->getStatus());
        $resolutions = $this->em->getRepository(AssemblyResolution::class)->findAll();
        self::assertCount(1, $resolutions);
        self::assertSame(AssemblyResolutionResult::REVIEW_REQUIRED, $resolutions[0]->getResult());

        $this->expectException(DomainException::class);
        $this->service->registerDeclaration(
            $this->manager,
            $window,
            $this->entry,
            $this->evidence,
            AssemblyAbsenteeSignatureMode::HAND_SIGNED,
            [$item->getId() => AssemblyVoteChoice::AGAINST],
            new DateTimeImmutable('2026-09-09T16:20:00Z'),
        );
    }

    /** @return array{AssemblyAbsenteeWindow, \App\Entity\AssemblyAgendaItem} */
    private function openWindow(string $deadline = '2026-09-10T15:00:00Z'): array
    {
        $item = $this->eligibleItem();
        $this->em->persist($item);
        $this->em->flush();
        $this->assembly->convene($this->manager, new DateTimeImmutable('2026-09-09T12:00:00Z'));
        $this->assembly->start($this->manager, new DateTimeImmutable('2026-09-09T14:00:00Z'));
        $item->open('Финално неприсъствено решение.', new DateTimeImmutable('2026-09-09T14:05:00Z'));
        $this->assembly->close($this->manager, new DateTimeImmutable('2026-09-09T15:00:00Z'));
        $this->em->flush();

        $window = $this->service->openWindow(
            $this->manager,
            $this->assembly,
            [$item],
            new DateTimeImmutable('2026-09-09T15:01:00Z'),
            new DateTimeImmutable($deadline),
            'Правно основание за неприсъствено гласуване',
        );

        self::assertInstanceOf(AssemblyAbsenteeWindow::class, $window);
        self::assertSame(AgendaItemStatus::ABSENTEE_WINDOW, $item->getStatus());

        return [$window, $item];
    }

    private function eligibleItem(): \App\Entity\AssemblyAgendaItem
    {
        return $this->assembly->addAgendaItem(
            1,
            'Неприсъствена точка',
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
    }
}
