<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AnimalRegistration;
use App\Entity\Charge;
use App\Entity\FeePolicy;
use App\Entity\FeePolicyUnitRule;
use App\Entity\Fund;
use App\Entity\HouseholdMember;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Enum\FeeCategory;
use App\Enum\FeeDistribution;
use App\Enum\FundType;
use App\Enum\UnitRelationType;
use App\Service\MonthlyChargeGenerator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MonthlyChargeGeneratorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

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
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function testPerUnitPolicyCreatesExactChargeSnapshotsWithExplicitRule(): void
    {
        $fund = new Fund('operating', 'Текуща поддръжка', FundType::OPERATING);
        $policy = FeePolicy::create(
            'maintenance',
            'Месечна поддръжка',
            $fund,
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_UNIT,
            850,
            new DateTimeImmutable('2026-09-01'),
            'ОС 01/2026, т. 4',
        );
        $unit12 = new Unit('12');
        $unit13 = new Unit('13');
        $rule = new FeePolicyUnitRule(
            $policy,
            $unit13,
            new DateTimeImmutable('2026-09-01'),
            'Допълнително натоварване по решение на ОС.',
            'ОС 01/2026, т. 6',
            '2.000',
            '1.500',
        );

        foreach ([$fund, $policy, $unit12, $unit13, $rule] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $generator = new MonthlyChargeGenerator($this->entityManager);
        $result = $generator->generate(
            new DateTimeImmutable('2026-09-18'),
            new DateTimeImmutable('2026-09-08 04:15:00 UTC'),
        );

        self::assertSame(2, $result->created);
        self::assertSame(0, $result->skipped);
        self::assertSame(3400, $result->totalAmountCents);

        $charges = $this->entityManager->getRepository(Charge::class)->findBy([], ['amountCents' => 'ASC']);
        self::assertCount(2, $charges);

        self::assertSame('12', $charges[0]->getUnit()->getDesignation());
        self::assertSame('1.0000', $charges[0]->getQuantity());
        self::assertSame(850, $charges[0]->getAmountCents());
        self::assertSame('per_unit', $charges[0]->getCalculationDetails()['distribution']);
        self::assertSame('1.000', $charges[0]->getCalculationDetails()['base_quantity']);
        self::assertSame('1.000', $charges[0]->getCalculationDetails()['multiplier']);

        self::assertSame('13', $charges[1]->getUnit()->getDesignation());
        self::assertSame('2.0000', $charges[1]->getQuantity());
        self::assertSame(2550, $charges[1]->getAmountCents());
        self::assertSame('2.000', $charges[1]->getCalculationDetails()['quantity_override']);
        self::assertSame('1.500', $charges[1]->getCalculationDetails()['multiplier']);
        self::assertSame('ОС 01/2026, т. 6', $charges[1]->getCalculationDetails()['unit_rule_decision_reference']);
    }

    public function testPerPersonPolicyDeduplicatesResidentsAddsHouseholdAndAnimalsAndAllowsExplicitOverride(): void
    {
        $fund = new Fund('operating', 'Текуща поддръжка', FundType::OPERATING);
        $policy = FeePolicy::create(
            'maintenance_person',
            'Поддръжка на човек',
            $fund,
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_PERSON,
            300,
            new DateTimeImmutable('2026-09-01'),
            'ОС 01/2026, т. 7',
            includeAnimalEquivalents: true,
        );

        $unit12 = new Unit('12');
        $unit13 = new Unit('13');
        $ivan = new Person('Иван', 'Иванов');
        $maria = new Person('Мария', 'Иванова');
        $ownerRelation = new UnitRelation(
            $ivan,
            $unit12,
            UnitRelationType::OWNER,
            new DateTimeImmutable('2026-01-01'),
            '100.0000',
        );
        $duplicateUserRelation = new UnitRelation(
            $ivan,
            $unit12,
            UnitRelationType::USER,
            new DateTimeImmutable('2026-01-01'),
        );
        $householdMember = new HouseholdMember(
            $maria,
            $ownerRelation,
            new DateTimeImmutable('2026-01-01'),
        );
        $animal = new AnimalRegistration($unit12, 'куче', 1, 'BG-123');
        $legalEntityRelation = UnitRelation::forLegalEntity(
            $unit13,
            UnitRelationType::OWNER,
            new DateTimeImmutable('2026-01-01'),
            'Дом Инвест ООД',
            '123456789',
            '100.0000',
        );
        $unit13Rule = new FeePolicyUnitRule(
            $policy,
            $unit13,
            new DateTimeImmutable('2026-09-01'),
            'Изрично определен брой по решение на ОС.',
            'ОС 01/2026, т. 8',
            '2.000',
        );

        foreach ([
            $fund,
            $policy,
            $unit12,
            $unit13,
            $ivan,
            $maria,
            $ownerRelation,
            $duplicateUserRelation,
            $householdMember,
            $animal,
            $legalEntityRelation,
            $unit13Rule,
        ] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $result = (new MonthlyChargeGenerator($this->entityManager))->generate(
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-09-08 04:20:00 UTC'),
        );

        self::assertSame(2, $result->created);
        self::assertSame(0, $result->skipped);
        self::assertSame(1500, $result->totalAmountCents);

        $charges = $this->chargesByDesignation();
        self::assertSame('3.0000', $charges['12']->getQuantity());
        self::assertSame(900, $charges['12']->getAmountCents());
        self::assertSame('per_person', $charges['12']->getCalculationDetails()['distribution']);
        self::assertSame(2, $charges['12']->getCalculationDetails()['base_occupancy_count']);
        self::assertSame(1, $charges['12']->getCalculationDetails()['animal_equivalents']);
        self::assertFalse($charges['12']->getCalculationDetails()['unoccupied_minimum_applied']);
        self::assertSame('effective_dated_register', $charges['12']->getCalculationDetails()['animal_source']);
        self::assertSame('2026-09-01', $charges['12']->getCalculationDetails()['animal_source_date']);

        self::assertSame('2.0000', $charges['13']->getQuantity());
        self::assertSame(600, $charges['13']->getAmountCents());
        self::assertSame(1, $charges['13']->getCalculationDetails()['base_occupancy_count']);
        self::assertSame(0, $charges['13']->getCalculationDetails()['animal_equivalents']);
        self::assertSame('2.000', $charges['13']->getCalculationDetails()['quantity_override']);
    }

    public function testPerPersonPolicyChargesOnePersonForUnitWithoutRegisteredOccupants(): void
    {
        $fund = new Fund('operating', 'Текуща поддръжка', FundType::OPERATING);
        $policy = FeePolicy::create(
            'maintenance_person',
            'Поддръжка на човек',
            $fund,
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_PERSON,
            300,
            new DateTimeImmutable('2026-09-01'),
            'ОС 01/2026, т. 7',
        );
        $unit = new Unit('14');

        foreach ([$fund, $policy, $unit] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $result = (new MonthlyChargeGenerator($this->entityManager))->generate(
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-09-08 04:20:00 UTC'),
        );

        self::assertSame(1, $result->created);
        self::assertSame(300, $result->totalAmountCents);

        $charge = $this->entityManager->getRepository(Charge::class)->findOneBy(['unit' => $unit]);
        self::assertInstanceOf(Charge::class, $charge);
        self::assertSame('1.0000', $charge->getQuantity());
        self::assertSame(0, $charge->getCalculationDetails()['base_occupancy_count']);
        self::assertTrue($charge->getCalculationDetails()['unoccupied_minimum_applied']);
    }

    public function testIdealPartsPolicyPreservesExactTotalAndAllocatesRemainderDeterministically(): void
    {
        $fund = new Fund('operating', 'Текуща поддръжка', FundType::OPERATING);
        $policy = FeePolicy::create(
            'maintenance_ideal',
            'Поддръжка по идеални части',
            $fund,
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::IDEAL_PARTS,
            1001,
            new DateTimeImmutable('2026-09-01'),
            'ОС 01/2026, т. 9',
        );
        $unit12 = new Unit('12', idealParts: '33.3333');
        $unit13 = new Unit('13', idealParts: '33.3333');
        $unit14 = new Unit('14', idealParts: '33.3334');

        foreach ([$fund, $policy, $unit12, $unit13, $unit14] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $result = (new MonthlyChargeGenerator($this->entityManager))->generate(
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-09-08 04:25:00 UTC'),
        );

        self::assertSame(3, $result->created);
        self::assertSame(0, $result->skipped);
        self::assertSame(1001, $result->totalAmountCents);

        $charges = $this->chargesByDesignation();
        self::assertSame('33.3333', $charges['12']->getQuantity());
        self::assertSame('33.3333', $charges['13']->getQuantity());
        self::assertSame('33.3334', $charges['14']->getQuantity());
        self::assertSame(334, $charges['12']->getAmountCents());
        self::assertSame(333, $charges['13']->getAmountCents());
        self::assertSame(334, $charges['14']->getAmountCents());
        self::assertSame('33.3333', $charges['12']->getCalculationDetails()['ideal_parts']);
        self::assertSame('33.3333', $charges['13']->getCalculationDetails()['ideal_parts']);
        self::assertSame('33.3334', $charges['14']->getCalculationDetails()['ideal_parts']);
        self::assertSame('100.0000', $charges['12']->getCalculationDetails()['ideal_parts_total']);
        self::assertSame('largest_remainder', $charges['12']->getCalculationDetails()['allocation_method']);
    }

    public function testIdealPartsPolicyRejectsMissingUnitSharesWithoutPostingPartialCharges(): void
    {
        $fund = new Fund('operating', 'Текуща поддръжка', FundType::OPERATING);
        $policy = FeePolicy::create(
            'maintenance_ideal',
            'Поддръжка по идеални части',
            $fund,
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::IDEAL_PARTS,
            1000,
            new DateTimeImmutable('2026-09-01'),
            'ОС 01/2026, т. 9',
        );
        $unit12 = new Unit('12', idealParts: '60.0000');
        $unit13 = new Unit('13');

        foreach ([$fund, $policy, $unit12, $unit13] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        try {
            (new MonthlyChargeGenerator($this->entityManager))->generate(
                new DateTimeImmutable('2026-09-01'),
                new DateTimeImmutable('2026-09-08 04:25:00 UTC'),
            );
            self::fail('Expected missing ideal parts to abort charge generation.');
        } catch (DomainException $exception) {
            self::assertSame('Unit "13" is missing ideal parts.', $exception->getMessage());
        }

        self::assertCount(0, $this->entityManager->getRepository(Charge::class)->findAll());
    }

    /** @return array<string, Charge> */
    private function chargesByDesignation(): array
    {
        $result = [];
        foreach ($this->entityManager->getRepository(Charge::class)->findAll() as $charge) {
            $result[$charge->getUnit()->getDesignation()] = $charge;
        }

        return $result;
    }
}
