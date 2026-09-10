<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AnimalRegistration;
use App\Entity\Charge;
use App\Entity\FeePolicy;
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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MonthlyChargeHistoricalOccupancyTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $tool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function testMonthlyChargesUseOccupancyAndAnimalsEffectiveForBillingMonth(): void
    {
        $fund = new Fund('operating-history', 'Текуща поддръжка', FundType::OPERATING);
        $policy = FeePolicy::create(
            'maintenance-history',
            'Поддръжка на човек',
            $fund,
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_PERSON,
            300,
            new DateTimeImmutable('2026-01-01'),
            'ОС 01/2026, т. 7',
            includeAnimalEquivalents: true,
        );
        $unit = new Unit('12');
        $owner = new Person('Иван', 'Иванов');
        $memberPerson = new Person('Мария', 'Иванова');
        $ownerRelation = new UnitRelation(
            $owner,
            $unit,
            UnitRelationType::OWNER,
            new DateTimeImmutable('2026-01-01'),
            '100.0000',
        );
        $member = new HouseholdMember($memberPerson, $ownerRelation, new DateTimeImmutable('2026-01-01'));
        $member->endAt(new DateTimeImmutable('2026-08-31'));
        $animals = new AnimalRegistration(
            $unit,
            'котки',
            2,
            null,
            new DateTimeImmutable('2026-09-01'),
        );

        foreach ([$fund, $policy, $unit, $owner, $memberPerson, $ownerRelation, $member, $animals] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $generator = new MonthlyChargeGenerator($this->entityManager);
        $generator->generate(new DateTimeImmutable('2026-08-15'), new DateTimeImmutable('2026-08-15T08:00:00Z'));
        $generator->generate(new DateTimeImmutable('2026-09-15'), new DateTimeImmutable('2026-09-15T08:00:00Z'));

        $august = $this->entityManager->getRepository(Charge::class)->findOneBy([
            'policy' => $policy,
            'unit' => $unit,
            'billingMonth' => new DateTimeImmutable('2026-08-01'),
        ]);
        $september = $this->entityManager->getRepository(Charge::class)->findOneBy([
            'policy' => $policy,
            'unit' => $unit,
            'billingMonth' => new DateTimeImmutable('2026-09-01'),
        ]);

        self::assertInstanceOf(Charge::class, $august);
        self::assertInstanceOf(Charge::class, $september);

        self::assertSame('2.0000', $august->getQuantity());
        self::assertSame(600, $august->getAmountCents());
        self::assertSame(2, $august->getCalculationDetails()['base_occupancy_count']);
        self::assertSame(0, $august->getCalculationDetails()['animal_equivalents']);
        self::assertSame('effective_dated_register', $august->getCalculationDetails()['animal_source']);
        self::assertSame('2026-08-01', $august->getCalculationDetails()['animal_source_date']);

        self::assertSame('3.0000', $september->getQuantity());
        self::assertSame(900, $september->getAmountCents());
        self::assertSame(1, $september->getCalculationDetails()['base_occupancy_count']);
        self::assertSame(2, $september->getCalculationDetails()['animal_equivalents']);
        self::assertSame('effective_dated_register', $september->getCalculationDetails()['animal_source']);
        self::assertSame('2026-09-01', $september->getCalculationDetails()['animal_source_date']);
    }
}
