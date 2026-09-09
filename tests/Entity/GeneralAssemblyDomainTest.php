<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AssemblyAgendaItem;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\AgendaItemStatus;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyDecisionKind;
use App\Enum\AssemblyVoteDenominator;
use App\Enum\GeneralAssemblyStatus;
use App\Enum\MajorityComparison;
use App\Value\AssemblyMajorityRuleSnapshot;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GeneralAssemblyDomainTest extends TestCase
{
    public function testDraftStoresNormalizedMeetingMetadata(): void
    {
        $this->assertDomainAvailable();
        $manager = $this->manager();

        $assembly = GeneralAssembly::draft(
            '  Редовно общо събрание  ',
            new DateTimeImmutable('2026-09-10 15:00:00 UTC'),
            '  Europe/Sofia  ',
            new DateTimeImmutable('2026-09-10 00:00:00 UTC'),
            '  Вход А, партер  ',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            '  Управител  ',
            $manager,
            $manager,
            new DateTimeImmutable('2026-09-09 09:00:00 UTC'),
        );

        self::assertSame(GeneralAssemblyStatus::DRAFT, $assembly->getStatus());
        self::assertSame('Редовно общо събрание', $assembly->getTitle());
        self::assertSame('Europe/Sofia', $assembly->getTimezoneSnapshot());
        self::assertSame('Вход А, партер', $assembly->getPlace());
        self::assertSame('Управител', $assembly->getInitiatorDisplayName());
        self::assertFalse($assembly->isResidentVisible());
    }

    public function testDraftRejectsBlankRequiredMeetingMetadata(): void
    {
        $this->assertDomainAvailable();
        $manager = $this->manager();

        $this->expectException(InvalidArgumentException::class);
        GeneralAssembly::draft(
            ' ',
            new DateTimeImmutable('2026-09-10 15:00:00 UTC'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-10 00:00:00 UTC'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Управител',
            $manager,
            $manager,
            new DateTimeImmutable('2026-09-09 09:00:00 UTC'),
        );
    }

    public function testOtherLegalBasisRequiresExplanation(): void
    {
        $this->assertDomainAvailable();
        $manager = $this->manager();

        $this->expectException(InvalidArgumentException::class);
        GeneralAssembly::draft(
            'Общо събрание',
            new DateTimeImmutable('2026-09-10 15:00:00 UTC'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-10 00:00:00 UTC'),
            'Вход А',
            AssemblyConveningBasis::OTHER_LEGAL_BASIS,
            'Инициатор',
            null,
            $manager,
            new DateTimeImmutable('2026-09-09 09:00:00 UTC'),
        );
    }

    public function testAgendaPositionMustBeUniqueAndPositive(): void
    {
        $this->assertDomainAvailable();
        $assembly = $this->draftAssembly();

        $assembly->addAgendaItem(1, 'Точка 1', null, 'Решение 1', AssemblyDecisionKind::ORDINARY, $this->rule());

        try {
            $assembly->addAgendaItem(1, 'Друга точка', null, 'Решение 2', AssemblyDecisionKind::ORDINARY, $this->rule());
            self::fail('Duplicate agenda position must be rejected.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $assembly->addAgendaItem(0, 'Невалидна точка', null, 'Решение', AssemblyDecisionKind::ORDINARY, $this->rule());
    }

    public function testConveningFreezesOrdinaryAgenda(): void
    {
        $this->assertDomainAvailable();
        $manager = $this->manager();
        $assembly = $this->draftAssembly($manager);
        $item = $assembly->addAgendaItem(
            1,
            'Избор на управител',
            null,
            'Общото събрание избира управител.',
            AssemblyDecisionKind::ELECTION_OR_REMOVAL,
            $this->rule(),
        );

        $assembly->convene($manager, new DateTimeImmutable('2026-09-09 11:00:00 UTC'));

        self::assertSame(GeneralAssemblyStatus::CONVENED, $assembly->getStatus());
        self::assertTrue($assembly->isResidentVisible());

        $this->expectException(DomainException::class);
        $item->reviseDraft('Ново заглавие', null, 'Нов текст', AssemblyDecisionKind::ORDINARY, $this->rule());
    }

    public function testEmergencyAgendaRequiresInProgressMeetingAndReason(): void
    {
        $this->assertDomainAvailable();
        $manager = $this->manager();
        $assembly = $this->draftAssembly($manager);

        try {
            $assembly->addEmergencyAgendaItem(1, 'Спешна точка', null, 'Решение', AssemblyDecisionKind::ORDINARY, $this->rule(), 'Авария');
            self::fail('Emergency agenda item before meeting start must be rejected.');
        } catch (DomainException) {
        }

        $assembly->convene($manager, new DateTimeImmutable('2026-09-09 11:00:00 UTC'));
        $assembly->start($manager, new DateTimeImmutable('2026-09-10 15:00:00 UTC'));

        try {
            $assembly->addEmergencyAgendaItem(1, 'Спешна точка', null, 'Решение', AssemblyDecisionKind::ORDINARY, $this->rule(), ' ');
            self::fail('Emergency agenda item without reason must be rejected.');
        } catch (InvalidArgumentException) {
        }

        $item = $assembly->addEmergencyAgendaItem(1, 'Спешна точка', null, 'Решение', AssemblyDecisionKind::ORDINARY, $this->rule(), 'Авария във входа');
        self::assertTrue($item->isEmergency());
        self::assertSame('Авария във входа', $item->getEmergencyReason());
    }

    public function testLifecycleRejectsOutOfOrderTransitions(): void
    {
        $this->assertDomainAvailable();
        $manager = $this->manager();
        $assembly = $this->draftAssembly($manager);

        try {
            $assembly->start($manager, new DateTimeImmutable('2026-09-10 15:00:00 UTC'));
            self::fail('Draft assembly must not start before convening.');
        } catch (DomainException) {
        }

        $assembly->convene($manager, new DateTimeImmutable('2026-09-09 11:00:00 UTC'));
        $assembly->start($manager, new DateTimeImmutable('2026-09-10 15:00:00 UTC'));
        $assembly->close($manager, new DateTimeImmutable('2026-09-10 17:00:00 UTC'));

        self::assertSame(GeneralAssemblyStatus::CLOSED, $assembly->getStatus());

        $this->expectException(DomainException::class);
        $assembly->close($manager, new DateTimeImmutable('2026-09-10 17:30:00 UTC'));
    }

    public function testMajorityRuleRejectsInvalidThreshold(): void
    {
        $this->assertDomainAvailable();

        $this->expectException(InvalidArgumentException::class);
        new AssemblyMajorityRuleSnapshot(
            'ordinary',
            AssemblyVoteDenominator::REPRESENTED_AT_MEETING,
            '100.00000001',
            MajorityComparison::AT_LEAST,
            'ЗУЕС',
            '2026-09-09',
        );
    }

    public function testAgendaItemStartsPlanned(): void
    {
        $this->assertDomainAvailable();
        $item = $this->draftAssembly()->addAgendaItem(1, 'Точка', null, 'Решение', AssemblyDecisionKind::ORDINARY, $this->rule());

        self::assertInstanceOf(AssemblyAgendaItem::class, $item);
        self::assertSame(AgendaItemStatus::PLANNED, $item->getStatus());
    }

    private function assertDomainAvailable(): void
    {
        foreach ([
            GeneralAssembly::class,
            AssemblyAgendaItem::class,
            AssemblyMajorityRuleSnapshot::class,
        ] as $class) {
            self::assertTrue(class_exists($class), sprintf('%s has not been implemented yet.', $class));
        }

        foreach ([
            GeneralAssemblyStatus::class,
            AssemblyConveningBasis::class,
            AssemblyDecisionKind::class,
            AgendaItemStatus::class,
            AssemblyVoteDenominator::class,
            MajorityComparison::class,
        ] as $enum) {
            self::assertTrue(enum_exists($enum), sprintf('%s has not been implemented yet.', $enum));
        }
    }

    private function rule(): AssemblyMajorityRuleSnapshot
    {
        return new AssemblyMajorityRuleSnapshot(
            'ordinary-represented-majority',
            AssemblyVoteDenominator::REPRESENTED_AT_MEETING,
            '50.00000000',
            MajorityComparison::GREATER_THAN,
            'ЗУЕС',
            'effective-through-2026-09-09',
        );
    }

    private function draftAssembly(?User $manager = null): GeneralAssembly
    {
        $manager ??= $this->manager();

        return GeneralAssembly::draft(
            'Редовно общо събрание',
            new DateTimeImmutable('2026-09-10 15:00:00 UTC'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-10 00:00:00 UTC'),
            'Вход А, партер',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Управител',
            $manager,
            $manager,
            new DateTimeImmutable('2026-09-09 09:00:00 UTC'),
        );
    }

    private function manager(): User
    {
        $user = new User(new Person('Мария', 'Иванова', email: 'manager-'.bin2hex(random_bytes(3)).'@example.com'), 'manager-'.bin2hex(random_bytes(3)).'@example.com', 'hash');
        // Person and User emails do not have to match in unit-only domain tests.
        $user->setRoles(['ROLE_MANAGER']);

        return $user;
    }
}
