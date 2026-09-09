<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssemblyAgendaItem;
use App\Entity\AssemblyAttendance;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\AssemblyResolution;
use App\Entity\AssemblyVote;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\AssemblyAttendanceMode;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyDecisionKind;
use App\Enum\AssemblyPrincipalType;
use App\Enum\AssemblyResolutionResult;
use App\Enum\AssemblyVoteChoice;
use App\Enum\AssemblyVoteDenominator;
use App\Enum\MajorityComparison;
use App\Service\AssemblyVotingService;
use App\Value\AssemblyMajorityRuleSnapshot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AssemblyVotingServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AssemblyVotingService $service;
    private User $manager;
    private GeneralAssembly $assembly;
    private AssemblyAgendaItem $item;
    private AssemblyElectorateEntry $entry;

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

        $service = self::getContainer()->get(AssemblyVotingService::class);
        self::assertInstanceOf(AssemblyVotingService::class, $service);
        $this->service = $service;

        $managerPerson = new Person('Мария', 'Управител', email: 'manager-voting@example.com');
        $this->manager = new User($managerPerson, 'manager-voting@example.com', 'hash');
        $this->manager->setRoles(['ROLE_MANAGER']);
        $owner = new Person('Анна', 'Собственик');
        $unit = new Unit('Ап. 1', idealParts: '60.0000');
        $this->assembly = GeneralAssembly::draft(
            'Общо събрание',
            new DateTimeImmutable('2026-09-20T15:00:00Z'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-09'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Мария Управител',
            $this->manager,
            $this->manager,
            new DateTimeImmutable('2026-09-09T08:00:00Z'),
        );
        $this->item = $this->assembly->addAgendaItem(
            1,
            'Ремонт',
            null,
            'Да се извърши ремонт.',
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
            '60',
            '60',
            true,
            null,
            new DateTimeImmutable('2026-09-09T08:05:00Z'),
        );

        foreach ([$managerPerson, $this->manager, $owner, $unit, $this->assembly, $this->item, $this->entry] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $this->assembly->convene($this->manager, new DateTimeImmutable('2026-09-19T12:00:00Z'));
        $this->assembly->start($this->manager, new DateTimeImmutable('2026-09-20T15:00:00Z'));
        $attendance = AssemblyAttendance::register(
            $this->assembly,
            $this->entry,
            AssemblyAttendanceMode::IN_PERSON,
            $this->manager,
            new DateTimeImmutable('2026-09-20T15:00:00Z'),
        );
        $em->persist($attendance);
        $em->flush();
    }

    public function testOpenVoteCorrectAndResolveUsesSnapshottedWeight(): void
    {
        $this->service->openItem(
            $this->manager,
            $this->item,
            'Да се извърши ремонтът по приложената оферта.',
            new DateTimeImmutable('2026-09-20T15:01:00Z'),
        );

        $vote = $this->service->recordVote(
            $this->manager,
            $this->item,
            $this->entry,
            AssemblyVoteChoice::AGAINST,
            new DateTimeImmutable('2026-09-20T15:02:00Z'),
        );
        self::assertSame('60.00000000', $vote->getWeightIdealPartsPercent());
        self::assertSame(AssemblyVoteChoice::AGAINST, $vote->getChoice());

        $correction = $this->service->correctVote(
            $this->manager,
            $vote,
            AssemblyVoteChoice::FOR,
            'Поправка на грешно въведен вот.',
            new DateTimeImmutable('2026-09-20T15:03:00Z'),
        );
        self::assertSame(AssemblyVoteChoice::AGAINST, $correction->getPreviousChoice());
        self::assertSame(AssemblyVoteChoice::FOR, $vote->getChoice());

        $resolution = $this->service->resolveItem(
            $this->manager,
            $this->item,
            new DateTimeImmutable('2026-09-20T15:04:00Z'),
        );
        self::assertSame(AssemblyResolutionResult::ACCEPTED, $resolution->getResult());
        self::assertSame('60.00000000', $resolution->getForIdealPartsPercent());
        self::assertCount(1, $this->em->getRepository(AssemblyResolution::class)->findAll());
    }

    public function testDuplicateVoteIsRejectedAndCorrectionAfterResolveIsRejected(): void
    {
        $this->service->openItem($this->manager, $this->item, 'Финално решение', new DateTimeImmutable('2026-09-20T15:01:00Z'));
        $vote = $this->service->recordVote(
            $this->manager,
            $this->item,
            $this->entry,
            AssemblyVoteChoice::FOR,
            new DateTimeImmutable('2026-09-20T15:02:00Z'),
        );

        try {
            $this->service->recordVote(
                $this->manager,
                $this->item,
                $this->entry,
                AssemblyVoteChoice::AGAINST,
                new DateTimeImmutable('2026-09-20T15:02:30Z'),
            );
            self::fail('Duplicate formal vote must be rejected.');
        } catch (DomainException) {
            self::assertCount(1, $this->em->getRepository(AssemblyVote::class)->findAll());
        }

        $first = $this->service->resolveItem($this->manager, $this->item, new DateTimeImmutable('2026-09-20T15:04:00Z'));
        $second = $this->service->resolveItem($this->manager, $this->item, new DateTimeImmutable('2026-09-20T15:05:00Z'));
        self::assertSame($first->getId(), $second->getId());
        self::assertCount(1, $this->em->getRepository(AssemblyResolution::class)->findAll());

        $this->expectException(DomainException::class);
        $this->service->correctVote(
            $this->manager,
            $vote,
            AssemblyVoteChoice::AGAINST,
            'Твърде късна корекция.',
            new DateTimeImmutable('2026-09-20T15:06:00Z'),
        );
    }

    public function testUnrepresentedPrincipalCannotVote(): void
    {
        $person = new Person('Борис', 'Неприсъстващ');
        $unit = new Unit('Ап. 2', idealParts: '40.0000');
        $entry = AssemblyElectorateEntry::snapshot(
            $this->assembly,
            $unit,
            null,
            'Ап. 2',
            AssemblyPrincipalType::PERSON,
            $person,
            'Борис Неприсъстващ',
            null,
            'owner',
            '100',
            '40',
            '40',
            true,
            null,
            new DateTimeImmutable('2026-09-09T08:05:00Z'),
        );
        $this->em->persist($person);
        $this->em->persist($unit);
        $this->em->persist($entry);
        $this->em->flush();

        $this->service->openItem($this->manager, $this->item, 'Финално решение', new DateTimeImmutable('2026-09-20T15:01:00Z'));

        $this->expectException(DomainException::class);
        $this->service->recordVote(
            $this->manager,
            $this->item,
            $entry,
            AssemblyVoteChoice::FOR,
            new DateTimeImmutable('2026-09-20T15:02:00Z'),
        );
    }
}
