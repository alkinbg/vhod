<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyLegalResult;
use App\Enum\AssemblyQuorumCheckKind;
use App\Service\AssemblyQuorumService;
use App\Value\AssemblyQuorumRuleSnapshot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AssemblyQuorumTimingServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private AssemblyQuorumService $service;
    private User $manager;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $tool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $service = self::getContainer()->get(AssemblyQuorumService::class);
        self::assertInstanceOf(AssemblyQuorumService::class, $service);
        $this->service = $service;

        $person = new Person('Мария', 'Управител', email: 'quorum-timing-manager@example.com');
        $this->manager = new User($person, 'quorum-timing-manager@example.com', 'hash');
        $this->manager->setRoles(['ROLE_MANAGER']);
        $this->entityManager->persist($person);
        $this->entityManager->persist($this->manager);
        $this->entityManager->flush();
    }

    public function testFirstCallCannotBeCheckedBeforeScheduledTime(): void
    {
        $assembly = $this->convenedAssembly('2026-09-21T15:00:00Z');

        $this->expectException(DomainException::class);
        $this->service->check(
            $this->manager,
            $assembly,
            AssemblyQuorumCheckKind::FIRST_CALL,
            new DateTimeImmutable('2026-09-21T14:59:59Z'),
        );
    }

    public function testDelayedCallCannotBeCheckedBeforeOneHourDelay(): void
    {
        $assembly = $this->convenedAssembly('2026-09-21T15:00:00Z');

        $this->expectException(DomainException::class);
        $this->service->check(
            $this->manager,
            $assembly,
            AssemblyQuorumCheckKind::DELAYED_CALL,
            new DateTimeImmutable('2026-09-21T15:59:59Z'),
        );
    }

    public function testDelayedCallIsAllowedAtExactlyOneHourAfterScheduledTime(): void
    {
        $assembly = $this->convenedAssembly('2026-09-21T15:00:00Z');

        $check = $this->service->check(
            $this->manager,
            $assembly,
            AssemblyQuorumCheckKind::DELAYED_CALL,
            new DateTimeImmutable('2026-09-21T16:00:00Z'),
        );

        self::assertSame('26.00000000', $check->getRequiredIdealPartsPercent());
        self::assertSame(AssemblyLegalResult::INVALID, $check->getResult());
    }

    public function testNextDayCallIsAnExplicitQuorumKind(): void
    {
        self::assertTrue(
            defined(AssemblyQuorumCheckKind::class.'::NEXT_DAY_CALL'),
            'NEXT_DAY_CALL must be represented explicitly instead of overloading DELAYED_CALL.',
        );
    }

    public function testNextDayCallCannotBeCheckedBeforeSameTimeOnNextEligibleDay(): void
    {
        self::assertTrue(defined(AssemblyQuorumCheckKind::class.'::NEXT_DAY_CALL'));
        $assembly = $this->convenedAssembly('2026-09-21T15:00:00Z');

        $this->expectException(DomainException::class);
        $this->service->check(
            $this->manager,
            $assembly,
            AssemblyQuorumCheckKind::NEXT_DAY_CALL,
            new DateTimeImmutable('2026-09-23T14:59:59Z'),
        );
    }

    public function testNextDayCallHasNoMinimumQuorumForOrdinaryCase(): void
    {
        self::assertTrue(defined(AssemblyQuorumCheckKind::class.'::NEXT_DAY_CALL'));
        $assembly = $this->convenedAssembly('2026-09-21T15:00:00Z');

        // 22 September is an official Bulgarian holiday, so the next eligible day is 23 September.
        $check = $this->service->check(
            $this->manager,
            $assembly,
            AssemblyQuorumCheckKind::NEXT_DAY_CALL,
            new DateTimeImmutable('2026-09-23T15:00:00Z'),
        );

        self::assertSame('0.00000000', $check->getRequiredIdealPartsPercent());
        self::assertSame(AssemblyLegalResult::VALID, $check->getResult());
        self::assertStringContainsString('next-day', $check->getRuleCode());
    }

    public function testNextEligibleDaySkipsWeekend(): void
    {
        self::assertTrue(defined(AssemblyQuorumCheckKind::class.'::NEXT_DAY_CALL'));
        $assembly = $this->convenedAssembly('2026-09-18T15:00:00Z'); // Friday.

        try {
            $this->service->check(
                $this->manager,
                $assembly,
                AssemblyQuorumCheckKind::NEXT_DAY_CALL,
                new DateTimeImmutable('2026-09-19T15:00:00Z'), // Saturday.
            );
            self::fail('A next-day quorum check must not be accepted on a weekend.');
        } catch (DomainException) {
        }

        $check = $this->service->check(
            $this->manager,
            $assembly,
            AssemblyQuorumCheckKind::NEXT_DAY_CALL,
            new DateTimeImmutable('2026-09-21T15:00:00Z'), // Monday.
        );

        self::assertSame(AssemblyLegalResult::VALID, $check->getResult());
    }

    public function testNextEligibleDaySkipsOfficialHolidayAndSubstituteNonWorkingDay(): void
    {
        self::assertTrue(defined(AssemblyQuorumCheckKind::class.'::NEXT_DAY_CALL'));
        $assembly = $this->convenedAssembly('2026-12-23T15:00:00Z');

        foreach (['2026-12-24T15:00:00Z', '2026-12-28T15:00:00Z'] as $tooEarly) {
            try {
                $this->service->check(
                    $this->manager,
                    $assembly,
                    AssemblyQuorumCheckKind::NEXT_DAY_CALL,
                    new DateTimeImmutable($tooEarly),
                );
                self::fail('A next-day quorum check must not be accepted on a statutory non-working day.');
            } catch (DomainException) {
            }
        }

        $check = $this->service->check(
            $this->manager,
            $assembly,
            AssemblyQuorumCheckKind::NEXT_DAY_CALL,
            new DateTimeImmutable('2026-12-29T15:00:00Z'),
        );

        self::assertSame(AssemblyLegalResult::VALID, $check->getResult());
    }

    private function convenedAssembly(string $scheduledAt): GeneralAssembly
    {
        $scheduled = new DateTimeImmutable($scheduledAt);
        $assembly = GeneralAssembly::draft(
            'Тест за кворум',
            $scheduled,
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-10'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Мария Управител',
            $this->manager,
            $this->manager,
            new DateTimeImmutable('2026-09-10T08:00:00Z'),
        );
        $assembly->snapshotQuorumRule(new AssemblyQuorumRuleSnapshot(
            'zues-2025-default',
            '51',
            '26',
            '51',
            '75',
            'ЗУЕС, чл. 15',
            'effective-through-2026-09-10',
            false,
        ));

        $this->entityManager->persist($assembly);
        $this->entityManager->flush();
        $assembly->convene($this->manager, new DateTimeImmutable('2026-09-17T12:00:00Z'));
        $this->entityManager->flush();

        return $assembly;
    }
}
