<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssemblyElectorateEntry;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyLegalResult;
use App\Enum\AssemblyPrincipalType;
use App\Enum\AssemblyQuorumCheckKind;
use App\Service\AssemblyQuorumCalculator;
use App\Value\AssemblyQuorumRuleSnapshot;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AssemblyQuorumCalculatorTest extends TestCase
{
    public function testFirstCallAtExactlyFiftyOnePercentIsValid(): void
    {
        $this->assertContractAvailable();
        $entry = $this->entry('51.00000000');

        $result = $this->calculator()->calculate($this->rule(), [$entry], [$entry], AssemblyQuorumCheckKind::FIRST_CALL);

        self::assertSame('51.00000000', $result->representedIdealPartsPercent);
        self::assertSame('51.00000000', $result->requiredIdealPartsPercent);
        self::assertSame(AssemblyLegalResult::VALID, $result->result);
    }

    public function testFirstCallImmediatelyBelowFiftyOnePercentIsInvalid(): void
    {
        $this->assertContractAvailable();
        $entry = $this->entry('50.99999999');

        $result = $this->calculator()->calculate($this->rule(), [$entry], [$entry], AssemblyQuorumCheckKind::FIRST_CALL);

        self::assertSame('50.99999999', $result->representedIdealPartsPercent);
        self::assertSame('51.00000000', $result->requiredIdealPartsPercent);
        self::assertSame(AssemblyLegalResult::INVALID, $result->result);
    }

    public function testDelayedCallAtExactlyTwentySixPercentIsValid(): void
    {
        $this->assertContractAvailable();
        $entry = $this->entry('26.00000000');

        $result = $this->calculator()->calculate($this->rule(), [$entry], [$entry], AssemblyQuorumCheckKind::DELAYED_CALL);

        self::assertSame('26.00000000', $result->representedIdealPartsPercent);
        self::assertSame('26.00000000', $result->requiredIdealPartsPercent);
        self::assertSame(AssemblyLegalResult::VALID, $result->result);
    }

    public function testDelayedCallImmediatelyBelowTwentySixPercentIsInvalid(): void
    {
        $this->assertContractAvailable();
        $entry = $this->entry('25.99999999');

        $result = $this->calculator()->calculate($this->rule(), [$entry], [$entry], AssemblyQuorumCheckKind::DELAYED_CALL);

        self::assertSame('25.99999999', $result->representedIdealPartsPercent);
        self::assertSame('26.00000000', $result->requiredIdealPartsPercent);
        self::assertSame(AssemblyLegalResult::INVALID, $result->result);
    }

    public function testPrincipalAboveFiftyOnePercentTriggersDominantOwnerRule(): void
    {
        $this->assertContractAvailable();
        $entry = $this->entry('51.00000001');

        $result = $this->calculator()->calculate($this->rule(), [$entry], [$entry], AssemblyQuorumCheckKind::FIRST_CALL);

        self::assertSame('51.00000001', $result->representedIdealPartsPercent);
        self::assertSame('75.00000000', $result->requiredIdealPartsPercent);
        self::assertStringContainsString('dominant', $result->ruleCode);
        self::assertSame(AssemblyLegalResult::INVALID, $result->result);
    }

    public function testExactlyFiftyOnePercentDoesNotTriggerDominantOwnerRule(): void
    {
        $this->assertContractAvailable();
        $entry = $this->entry('51.00000000');

        $result = $this->calculator()->calculate($this->rule(), [$entry], [$entry], AssemblyQuorumCheckKind::FIRST_CALL);

        self::assertSame('51.00000000', $result->requiredIdealPartsPercent);
        self::assertStringNotContainsString('dominant', $result->ruleCode);
        self::assertSame(AssemblyLegalResult::VALID, $result->result);
    }

    public function testDuplicateRepresentedEntryCannotIncreaseQuorumWeight(): void
    {
        $this->assertContractAvailable();
        $entry = $this->entry('26.00000000');

        $result = $this->calculator()->calculate($this->rule(), [$entry], [$entry, $entry], AssemblyQuorumCheckKind::DELAYED_CALL);

        self::assertSame('26.00000000', $result->representedIdealPartsPercent);
        self::assertSame(AssemblyLegalResult::VALID, $result->result);
    }

    public function testMaterialElectorateReviewForcesReviewRequiredInsteadOfLegalConclusion(): void
    {
        $this->assertContractAvailable();
        $known = $this->entry('51.00000000');
        $review = $this->reviewEntry('Липсват идеални части за самостоятелен обект.');

        $result = $this->calculator()->calculate($this->rule(), [$known, $review], [$known], AssemblyQuorumCheckKind::FIRST_CALL);

        self::assertSame('51.00000000', $result->representedIdealPartsPercent);
        self::assertSame(AssemblyLegalResult::REVIEW_REQUIRED, $result->result);
        self::assertNotSame('', trim($result->explanation));
    }

    private function calculator(): AssemblyQuorumCalculator
    {
        return new AssemblyQuorumCalculator();
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

    private function entry(string $weight, ?Person $person = null): AssemblyElectorateEntry
    {
        $person ??= new Person('Собственик', str_replace('.', '', $weight));

        return AssemblyElectorateEntry::snapshot(
            $this->assembly(),
            null,
            null,
            'Тестов обект '.$weight,
            AssemblyPrincipalType::PERSON,
            $person,
            $person->getDisplayName(),
            null,
            'owner',
            '100.00000000',
            $weight,
            $weight,
            true,
            null,
            new DateTimeImmutable('2026-09-09T08:00:00Z'),
        );
    }

    private function reviewEntry(string $reason): AssemblyElectorateEntry
    {
        $person = new Person('Неясен', 'Собственик');

        return AssemblyElectorateEntry::snapshot(
            $this->assembly(),
            null,
            null,
            'Неясен обект',
            AssemblyPrincipalType::PERSON,
            $person,
            $person->getDisplayName(),
            null,
            'owner',
            null,
            null,
            null,
            false,
            $reason,
            new DateTimeImmutable('2026-09-09T08:00:00Z'),
        );
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

    private function assertContractAvailable(): void
    {
        self::assertTrue(enum_exists(AssemblyLegalResult::class), 'AssemblyLegalResult has not been implemented yet.');
        self::assertTrue(enum_exists(AssemblyQuorumCheckKind::class), 'AssemblyQuorumCheckKind has not been implemented yet.');
        self::assertTrue(class_exists(AssemblyQuorumRuleSnapshot::class), 'AssemblyQuorumRuleSnapshot has not been implemented yet.');
        self::assertTrue(class_exists(AssemblyQuorumCalculator::class), 'AssemblyQuorumCalculator has not been implemented yet.');
    }
}
