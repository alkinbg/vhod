<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssemblyAttendance;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\AssemblyProxy;
use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\AssemblyAttendanceMode;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyDecisionKind;
use App\Enum\AssemblyPrincipalType;
use App\Enum\AssemblyQuorumCheckKind;
use App\Enum\AssemblyVoteDenominator;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Enum\GeneralAssemblyStatus;
use App\Enum\MajorityComparison;
use App\Service\AssemblyAbsenteeVotingService;
use App\Service\AssemblyAttendanceService;
use App\Service\AssemblyElectorateSnapshotService;
use App\Service\AssemblyProxyService;
use App\Service\AssemblyQuorumService;
use App\Service\AssemblyVotingService;
use App\Value\AssemblyMajorityRuleSnapshot;
use App\Value\AssemblyQuorumRuleSnapshot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GeneralAssemblyFinalizedImmutabilityTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $manager;
    private GeneralAssembly $assembly;
    private AssemblyElectorateEntry $attendanceEntry;
    private AssemblyElectorateEntry $proxyEntry;
    private AssemblyAttendance $attendance;
    private AssemblyProxy $proxy;
    private Document $proxyEvidence;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $managerPerson = new Person('Мария', 'Управител', email: 'finalized-manager@example.com');
        $this->manager = new User($managerPerson, 'finalized-manager@example.com', 'hash');
        $this->manager->setRoles(['ROLE_MANAGER']);

        $firstOwner = new Person('Анна', 'Собственик');
        $secondOwner = new Person('Борис', 'Собственик');
        $firstUnit = new Unit('Ап. 1', idealParts: '60.0000');
        $secondUnit = new Unit('Ап. 2', idealParts: '40.0000');

        $this->assembly = GeneralAssembly::draft(
            'Финализирано общо събрание',
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
        $this->assembly->snapshotQuorumRule(new AssemblyQuorumRuleSnapshot(
            'zues-finalized-test',
            '51',
            '26',
            '51',
            '75',
            'ЗУЕС — тестов кворум',
            '2026-09-09',
            false,
        ));
        $agendaItem = $this->assembly->addAgendaItem(
            1,
            'Запазена точка',
            null,
            'Запазен проект за решение.',
            AssemblyDecisionKind::ORDINARY,
            $this->majorityRule(),
        );

        $this->attendanceEntry = AssemblyElectorateEntry::snapshot(
            $this->assembly,
            $firstUnit,
            null,
            'Ап. 1',
            AssemblyPrincipalType::PERSON,
            $firstOwner,
            'Анна Собственик',
            null,
            'owner',
            '100',
            '60',
            '60',
            true,
            null,
            new DateTimeImmutable('2026-09-09T09:00:00Z'),
        );
        $this->proxyEntry = AssemblyElectorateEntry::snapshot(
            $this->assembly,
            $secondUnit,
            null,
            'Ап. 2',
            AssemblyPrincipalType::PERSON,
            $secondOwner,
            'Борис Собственик',
            null,
            'owner',
            '100',
            '40',
            '40',
            true,
            null,
            new DateTimeImmutable('2026-09-09T09:00:00Z'),
        );

        $this->proxyEvidence = Document::record(
            DocumentCategory::MEETING_PROXY,
            DocumentAccessLevel::GOVERNANCE,
            'Пълномощно',
            null,
            'proxy.pdf',
            'abababababababababababababababab.pdf',
            'application/pdf',
            100,
            $this->manager,
            new DateTimeImmutable('2026-09-20T14:30:00Z'),
        );
        $minutes = Document::record(
            DocumentCategory::MEETING_MINUTES,
            DocumentAccessLevel::RESIDENTS,
            'Финален протокол',
            null,
            'minutes.pdf',
            'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd.pdf',
            'application/pdf',
            200,
            $this->manager,
            new DateTimeImmutable('2026-09-20T17:00:00Z'),
        );

        foreach ([
            $managerPerson,
            $this->manager,
            $firstOwner,
            $secondOwner,
            $firstUnit,
            $secondUnit,
            $this->assembly,
            $agendaItem,
            $this->attendanceEntry,
            $this->proxyEntry,
            $this->proxyEvidence,
            $minutes,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $this->assembly->convene($this->manager, new DateTimeImmutable('2026-09-19T12:00:00Z'));
        $this->attendance = AssemblyAttendance::register(
            $this->assembly,
            $this->attendanceEntry,
            AssemblyAttendanceMode::IN_PERSON,
            $this->manager,
            new DateTimeImmutable('2026-09-20T14:45:00Z'),
        );
        $this->proxy = AssemblyProxy::register(
            $this->assembly,
            $this->proxyEntry,
            null,
            'Петър Пълномощник',
            'писмено пълномощно',
            $this->proxyEvidence,
            $this->manager,
            new DateTimeImmutable('2026-09-20T14:40:00Z'),
        );
        $em->persist($this->attendance);
        $em->persist($this->proxy);
        $em->flush();

        $this->assembly->start($this->manager, new DateTimeImmutable('2026-09-20T15:00:00Z'));
        $this->assembly->close($this->manager, new DateTimeImmutable('2026-09-20T16:00:00Z'));
        $this->assembly->setMinutesMetadata('Иван Председател', 'Елена Протоколчик');
        $this->assembly->finalizeMinutes(
            $minutes,
            $this->manager,
            new DateTimeImmutable('2026-09-20T17:00:00Z'),
            new DateTimeImmutable('2026-09-27'),
        );
        $em->flush();

        self::assertSame(GeneralAssemblyStatus::MINUTES_FINALIZED, $this->assembly->getStatus());
    }

    public function testAllOrdinaryMutationServicesRejectFinalizedMeeting(): void
    {
        $this->assertDomainRejected(
            fn () => $this->assembly->addAgendaItem(2, 'Нова точка', null, 'Ново решение', AssemblyDecisionKind::ORDINARY, $this->majorityRule()),
            'Agenda must be frozen after minutes finalization.',
        );
        $this->assertDomainRejected(
            fn () => self::getContainer()->get(AssemblyElectorateSnapshotService::class)->createSnapshot($this->assembly, new DateTimeImmutable('2026-09-20T17:01:00Z')),
            'Electorate snapshot must be immutable after finalization.',
        );
        $this->assertDomainRejected(
            fn () => self::getContainer()->get(AssemblyAttendanceService::class)->register(
                $this->manager,
                $this->assembly,
                $this->proxyEntry,
                AssemblyAttendanceMode::IN_PERSON,
                new DateTimeImmutable('2026-09-20T17:02:00Z'),
            ),
            'Attendance registration must be blocked after finalization.',
        );
        $this->assertDomainRejected(
            fn () => self::getContainer()->get(AssemblyAttendanceService::class)->correct(
                $this->manager,
                $this->attendance,
                AssemblyAttendanceMode::ONLINE,
                'Късна корекция',
                new DateTimeImmutable('2026-09-20T17:03:00Z'),
            ),
            'Attendance correction must be blocked after finalization.',
        );
        $this->assertDomainRejected(
            fn () => self::getContainer()->get(AssemblyProxyService::class)->register(
                $this->manager,
                $this->assembly,
                $this->attendanceEntry,
                null,
                'Нов представител',
                'писмено пълномощно',
                $this->proxyEvidence,
                new DateTimeImmutable('2026-09-20T17:04:00Z'),
            ),
            'Proxy registration must be blocked after finalization.',
        );
        $this->assertDomainRejected(
            fn () => self::getContainer()->get(AssemblyProxyService::class)->revoke(
                $this->manager,
                $this->proxy,
                'Късно оттегляне',
                new DateTimeImmutable('2026-09-20T17:05:00Z'),
            ),
            'Proxy revocation must be blocked after finalization.',
        );
        $this->assertDomainRejected(
            fn () => self::getContainer()->get(AssemblyQuorumService::class)->check(
                $this->manager,
                $this->assembly,
                AssemblyQuorumCheckKind::FIRST_CALL,
                new DateTimeImmutable('2026-09-20T17:06:00Z'),
            ),
            'Quorum history must not accept new checks after finalization.',
        );

        $item = $this->assembly->getAgendaItems()->first();
        self::assertNotFalse($item);
        $this->assertDomainRejected(
            fn () => self::getContainer()->get(AssemblyVotingService::class)->openItem(
                $this->manager,
                $item,
                'Късен финален текст',
                new DateTimeImmutable('2026-09-20T17:07:00Z'),
            ),
            'Formal voting must be blocked after finalization.',
        );
        $this->assertDomainRejected(
            fn () => self::getContainer()->get(AssemblyAbsenteeVotingService::class)->openWindow(
                $this->manager,
                $this->assembly,
                [$item],
                new DateTimeImmutable('2026-09-20T17:08:00Z'),
                new DateTimeImmutable('2026-09-21T17:08:00Z'),
                'Късно неприсъствено гласуване',
            ),
            'Absentee workflow must be blocked after finalization.',
        );
        $this->assertDomainRejected(
            fn () => $this->assembly->setMinutesMetadata('Друг председател', 'Друг протоколчик'),
            'Final minutes metadata must be immutable.',
        );
    }

    private function majorityRule(): AssemblyMajorityRuleSnapshot
    {
        return new AssemblyMajorityRuleSnapshot(
            'finalized-test-majority',
            AssemblyVoteDenominator::REPRESENTED_AT_MEETING,
            '50',
            MajorityComparison::GREATER_THAN,
            'ЗУЕС — тестово мнозинство',
            '2026-09-09',
        );
    }

    private function assertDomainRejected(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail($message);
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
    }
}
