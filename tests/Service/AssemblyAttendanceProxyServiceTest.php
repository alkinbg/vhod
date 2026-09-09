<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssemblyAttendance;
use App\Entity\AssemblyAttendanceChange;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\AssemblyProxy;
use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\AssemblyAttendanceMode;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyPrincipalType;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Service\AssemblyAttendanceService;
use App\Service\AssemblyProxyService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AssemblyAttendanceProxyServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $manager;
    private GeneralAssembly $assembly;
    /** @var list<AssemblyElectorateEntry> */
    private array $entries = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $managerPerson = new Person('Мария', 'Управител');
        $this->manager = new User($managerPerson, 'attendance-manager@example.com', 'hash');
        $this->manager->setRoles(['ROLE_MANAGER']);
        $this->em->persist($managerPerson);
        $this->em->persist($this->manager);

        $this->assembly = GeneralAssembly::draft(
            'Събрание за присъствие',
            new DateTimeImmutable('2026-09-20T15:00:00Z'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-09'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Мария Управител',
            $this->manager,
            $this->manager,
            new DateTimeImmutable('2026-09-09T07:00:00Z'),
        );
        $this->em->persist($this->assembly);

        foreach ([1, 2, 3, 4, 5] as $number) {
            $person = new Person('Собственик', (string) $number);
            $unit = new Unit('Ап. '.$number, idealParts: '20.0000');
            $entry = AssemblyElectorateEntry::snapshot(
                $this->assembly,
                $unit,
                null,
                $unit->getDesignation(),
                AssemblyPrincipalType::PERSON,
                $person,
                'Собственик '.$number,
                null,
                'owner',
                '100',
                '20',
                '20',
                true,
                null,
                new DateTimeImmutable('2026-09-09T10:00:00Z'),
            );
            $this->em->persist($person);
            $this->em->persist($unit);
            $this->em->persist($entry);
            $this->entries[] = $entry;
        }
        $this->em->flush();
        $this->assembly->convene($this->manager, new DateTimeImmutable('2026-09-19T12:00:00Z'));
        $this->em->flush();
    }

    public function testRegistersInPersonAndOnlineExactlyOncePerPrincipal(): void
    {
        self::assertTrue(class_exists(AssemblyAttendanceService::class), 'AssemblyAttendanceService has not been implemented yet.');
        self::assertTrue(class_exists(AssemblyAttendanceMode::class), 'AssemblyAttendanceMode has not been implemented yet.');
        $service = $this->attendanceService();

        $first = $service->register($this->manager, $this->assembly, $this->entries[0], AssemblyAttendanceMode::IN_PERSON, new DateTimeImmutable('2026-09-20T17:45:00+03:00'));
        $second = $service->register($this->manager, $this->assembly, $this->entries[1], AssemblyAttendanceMode::ONLINE, new DateTimeImmutable('2026-09-20T17:46:00+03:00'));

        self::assertSame(AssemblyAttendanceMode::IN_PERSON, $first->getMode());
        self::assertSame('2026-09-20T14:45:00+00:00', $first->getRegisteredAt()->format('Y-m-d\TH:i:sP'));
        self::assertSame(AssemblyAttendanceMode::ONLINE, $second->getMode());

        $this->expectException(DomainException::class);
        $service->register($this->manager, $this->assembly, $this->entries[0], AssemblyAttendanceMode::IN_PERSON, new DateTimeImmutable('2026-09-20T14:50:00Z'));
    }

    public function testStatutoryAuthorityRequiresNonBlankNote(): void
    {
        self::assertTrue(class_exists(AssemblyAttendanceService::class), 'AssemblyAttendanceService has not been implemented yet.');
        $service = $this->attendanceService();

        $this->expectException(InvalidArgumentException::class);
        $service->register(
            $this->manager,
            $this->assembly,
            $this->entries[0],
            AssemblyAttendanceMode::STATUTORY_USER_AUTHORITY,
            new DateTimeImmutable('2026-09-20T14:45:00Z'),
            authorityNote: '   ',
        );
    }

    public function testByProxyRequiresEffectiveProxyAndCopiesRepresentative(): void
    {
        self::assertTrue(class_exists(AssemblyProxyService::class), 'AssemblyProxyService has not been implemented yet.');
        $attendance = $this->attendanceService();

        try {
            $attendance->register($this->manager, $this->assembly, $this->entries[0], AssemblyAttendanceMode::BY_PROXY, new DateTimeImmutable('2026-09-20T14:45:00Z'));
            self::fail('BY_PROXY attendance without an effective proxy must be rejected.');
        } catch (DomainException) {
        }

        $representative = new Person('Петър', 'Пълномощник');
        $this->em->persist($representative);
        $this->em->flush();
        $proxy = $this->proxyService()->register(
            $this->manager,
            $this->assembly,
            $this->entries[0],
            $representative,
            'Петър Пълномощник',
            'писмено пълномощно',
            $this->governanceEvidence('a'),
            new DateTimeImmutable('2026-09-20T14:40:00Z'),
        );

        $registered = $attendance->register($this->manager, $this->assembly, $this->entries[0], AssemblyAttendanceMode::BY_PROXY, new DateTimeImmutable('2026-09-20T14:45:00Z'));
        self::assertSame($representative, $registered->getRepresentativePerson());
        self::assertSame('Петър Пълномощник', $registered->getRepresentativeName());
        self::assertSame($proxy, $this->proxyService()->effectiveForPrincipal($this->entries[0]));
    }

    public function testPersonalAttendanceAndProxyCannotOverlapAndRepresentativeIsLimitedToThreePrincipals(): void
    {
        self::assertTrue(class_exists(AssemblyProxyService::class), 'AssemblyProxyService has not been implemented yet.');
        $attendance = $this->attendanceService();
        $proxyService = $this->proxyService();
        $representative = new Person('Общ', 'Пълномощник');
        $this->em->persist($representative);
        $this->em->flush();

        $attendance->register($this->manager, $this->assembly, $this->entries[0], AssemblyAttendanceMode::IN_PERSON, new DateTimeImmutable('2026-09-20T14:30:00Z'));
        try {
            $proxyService->register($this->manager, $this->assembly, $this->entries[0], $representative, 'Общ Пълномощник', 'писмено', $this->governanceEvidence('b'), new DateTimeImmutable('2026-09-20T14:35:00Z'));
            self::fail('Personally present principal must not be proxied.');
        } catch (DomainException) {
        }

        foreach ([1, 2, 3] as $index) {
            $proxyService->register($this->manager, $this->assembly, $this->entries[$index], $representative, 'Общ Пълномощник', 'писмено', $this->governanceEvidence((string) ($index + 2)), new DateTimeImmutable('2026-09-20T14:36:00Z'));
        }

        $this->expectException(DomainException::class);
        $proxyService->register($this->manager, $this->assembly, $this->entries[4], $representative, 'Общ Пълномощник', 'писмено', $this->governanceEvidence('f'), new DateTimeImmutable('2026-09-20T14:37:00Z'));
    }

    public function testProxyRequiresGovernanceEvidenceAndRevocationIsAudited(): void
    {
        self::assertTrue(class_exists(AssemblyProxy::class), 'AssemblyProxy has not been implemented yet.');
        $proxyService = $this->proxyService();
        $representative = new Person('Петър', 'Пълномощник');
        $this->em->persist($representative);
        $this->em->flush();

        $residentDocument = Document::record(DocumentCategory::MEETING_PROXY, DocumentAccessLevel::RESIDENTS, 'Bad', null, 'bad.pdf', str_repeat('1', 32).'.pdf', 'application/pdf', 10, $this->manager, new DateTimeImmutable('2026-09-20T14:00:00Z'));
        $this->em->persist($residentDocument);
        $this->em->flush();
        try {
            $proxyService->register($this->manager, $this->assembly, $this->entries[0], $representative, 'Петър Пълномощник', 'писмено', $residentDocument, new DateTimeImmutable('2026-09-20T14:10:00Z'));
            self::fail('Proxy evidence outside GOVERNANCE access must be rejected.');
        } catch (InvalidArgumentException) {
        }

        $proxy = $proxyService->register($this->manager, $this->assembly, $this->entries[0], $representative, 'Петър Пълномощник', 'писмено', $this->governanceEvidence('9'), new DateTimeImmutable('2026-09-20T14:12:00Z'));
        $proxyService->revoke($this->manager, $proxy, 'Оттеглено пълномощно', new DateTimeImmutable('2026-09-20T17:20:00+03:00'));

        self::assertTrue($proxy->isRevoked());
        self::assertSame('Оттеглено пълномощно', $proxy->getRevocationReason());
        self::assertSame($this->manager, $proxy->getRevokedBy());
        self::assertSame('2026-09-20T14:20:00+00:00', $proxy->getRevokedAt()?->format('Y-m-d\TH:i:sP'));
    }

    public function testAttendanceCorrectionCreatesAppendOnlyAuditFact(): void
    {
        self::assertTrue(class_exists(AssemblyAttendanceChange::class), 'AssemblyAttendanceChange has not been implemented yet.');
        $service = $this->attendanceService();
        $attendance = $service->register($this->manager, $this->assembly, $this->entries[0], AssemblyAttendanceMode::IN_PERSON, new DateTimeImmutable('2026-09-20T14:30:00Z'));

        $change = $service->correct($this->manager, $attendance, AssemblyAttendanceMode::ONLINE, 'Корекция след проверка', new DateTimeImmutable('2026-09-20T17:35:00+03:00'));

        self::assertSame(AssemblyAttendanceMode::IN_PERSON, $change->getOldMode());
        self::assertSame(AssemblyAttendanceMode::ONLINE, $change->getNewMode());
        self::assertSame('Корекция след проверка', $change->getReason());
        self::assertSame($this->manager, $change->getChangedBy());
        self::assertSame('2026-09-20T14:35:00+00:00', $change->getChangedAt()->format('Y-m-d\TH:i:sP'));
        self::assertSame(AssemblyAttendanceMode::ONLINE, $attendance->getMode());
    }

    public function testAttendanceAndProxyMutationAreRejectedAfterMeetingCloses(): void
    {
        self::assertTrue(class_exists(AssemblyAttendanceService::class), 'AssemblyAttendanceService has not been implemented yet.');
        $this->assembly->start($this->manager, new DateTimeImmutable('2026-09-20T15:00:00Z'));
        $this->assembly->close($this->manager, new DateTimeImmutable('2026-09-20T16:00:00Z'));
        $this->em->flush();

        try {
            $this->attendanceService()->register($this->manager, $this->assembly, $this->entries[0], AssemblyAttendanceMode::IN_PERSON, new DateTimeImmutable('2026-09-20T16:01:00Z'));
            self::fail('Attendance must not mutate after close.');
        } catch (DomainException) {
        }

        $this->expectException(DomainException::class);
        $this->proxyService()->register($this->manager, $this->assembly, $this->entries[1], null, 'Външен представител', 'писмено', $this->governanceEvidence('e'), new DateTimeImmutable('2026-09-20T16:02:00Z'));
    }

    private function attendanceService(): AssemblyAttendanceService
    {
        $service = self::getContainer()->get(AssemblyAttendanceService::class);
        self::assertInstanceOf(AssemblyAttendanceService::class, $service);

        return $service;
    }

    private function proxyService(): AssemblyProxyService
    {
        $service = self::getContainer()->get(AssemblyProxyService::class);
        self::assertInstanceOf(AssemblyProxyService::class, $service);

        return $service;
    }

    private function governanceEvidence(string $seed): Document
    {
        $hex = str_pad(substr(str_repeat($seed, 32), 0, 32), 32, '0');
        $hex = preg_replace('/[^a-f0-9]/', 'a', strtolower($hex)) ?? str_repeat('a', 32);
        $document = Document::record(
            DocumentCategory::MEETING_PROXY,
            DocumentAccessLevel::GOVERNANCE,
            'Пълномощно',
            null,
            'proxy.pdf',
            $hex.'.pdf',
            'application/pdf',
            100,
            $this->manager,
            new DateTimeImmutable('2026-09-20T14:00:00Z'),
        );
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }
}
