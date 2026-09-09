<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AssemblyQuorumCheck;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyLegalResult;
use App\Enum\AssemblyQuorumCheckKind;
use App\Value\AssemblyQuorumCalculation;
use App\Value\AssemblyQuorumRuleSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AssemblyQuorumCheckTest extends TestCase
{
    public function testMeetingStoresOneImmutableQuorumRuleSnapshotWhileDraft(): void
    {
        self::assertTrue(method_exists(GeneralAssembly::class, 'snapshotQuorumRule'), 'GeneralAssembly quorum rule snapshot API has not been implemented yet.');
        self::assertTrue(method_exists(GeneralAssembly::class, 'getQuorumRuleSnapshot'), 'GeneralAssembly quorum rule snapshot getter has not been implemented yet.');

        $assembly = $this->assembly();
        $rule = $this->rule();
        $assembly->snapshotQuorumRule($rule);

        $stored = $assembly->getQuorumRuleSnapshot();
        self::assertInstanceOf(AssemblyQuorumRuleSnapshot::class, $stored);
        self::assertSame('zues-2025-default', $stored->code);
        self::assertSame('51.00000000', $stored->firstCallRequiredPercent);
        self::assertSame('26.00000000', $stored->delayedCallRequiredPercent);
        self::assertSame('75.00000000', $stored->dominantOwnerRequiredPercent);

        $this->expectException(DomainException::class);
        $assembly->snapshotQuorumRule($rule);
    }

    public function testQuorumCheckCopiesCalculationAndStoresUtcTimestamp(): void
    {
        self::assertTrue(class_exists(AssemblyQuorumCheck::class), 'AssemblyQuorumCheck has not been implemented yet.');

        $assembly = $this->assembly();
        $manager = $assembly->getCreatedBy();
        $calculation = new AssemblyQuorumCalculation(
            '51.00000000',
            '51.00000000',
            'zues-2025-default:first-call',
            AssemblyLegalResult::VALID,
            'Кворумът е достигнат.',
        );

        $check = AssemblyQuorumCheck::record(
            $assembly,
            AssemblyQuorumCheckKind::FIRST_CALL,
            new DateTimeImmutable('2026-09-20T18:05:00+03:00'),
            $calculation,
            $manager,
        );

        self::assertSame($assembly, $check->getAssembly());
        self::assertSame(AssemblyQuorumCheckKind::FIRST_CALL, $check->getKind());
        self::assertSame('2026-09-20T15:05:00+00:00', $check->getCheckedAt()->format('Y-m-d\TH:i:sP'));
        self::assertSame('51.00000000', $check->getRepresentedIdealPartsPercent());
        self::assertSame('51.00000000', $check->getRequiredIdealPartsPercent());
        self::assertSame('zues-2025-default:first-call', $check->getRuleCode());
        self::assertSame(AssemblyLegalResult::VALID, $check->getResult());
        self::assertSame('Кворумът е достигнат.', $check->getExplanation());
        self::assertSame($manager, $check->getCheckedBy());
        self::assertSame('UTC', $check->getCheckedAt()->getTimezone()->getName());
    }

    private function assembly(): GeneralAssembly
    {
        $managerPerson = new Person('Мария', 'Управител');
        $manager = new User($managerPerson, 'manager@example.com', 'hash');
        $manager->setRoles(['ROLE_MANAGER']);

        return GeneralAssembly::draft(
            'Тестово общо събрание',
            new DateTimeImmutable('2026-09-20T15:00:00Z'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-09'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Мария Управител',
            $manager,
            $manager,
            new DateTimeImmutable('2026-09-09T07:00:00Z'),
        );
    }

    private function rule(): AssemblyQuorumRuleSnapshot
    {
        return new AssemblyQuorumRuleSnapshot(
            'zues-2025-default',
            '51.00000000',
            '26.00000000',
            '51.00000000',
            '75.00000000',
            'ЗУЕС — приложим кворум към 2026-09-09',
            'effective-through-2026-09-09',
            false,
        );
    }
}
