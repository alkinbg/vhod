<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Charge;
use App\Entity\FeePolicy;
use App\Entity\FeePolicyUnitRule;
use App\Entity\Fund;
use App\Entity\Unit;
use App\Enum\FeeCategory;
use App\Enum\FeeDistribution;
use App\Enum\FundType;
use App\Service\MonthlyChargeGenerator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
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
        self::assertSame('1.000', $charges[0]->getQuantity());
        self::assertSame(850, $charges[0]->getAmountCents());
        self::assertSame('per_unit', $charges[0]->getCalculationDetails()['distribution']);
        self::assertSame('1.000', $charges[0]->getCalculationDetails()['base_quantity']);
        self::assertSame('1.000', $charges[0]->getCalculationDetails()['multiplier']);

        self::assertSame('13', $charges[1]->getUnit()->getDesignation());
        self::assertSame('2.000', $charges[1]->getQuantity());
        self::assertSame(2550, $charges[1]->getAmountCents());
        self::assertSame('2.000', $charges[1]->getCalculationDetails()['quantity_override']);
        self::assertSame('1.500', $charges[1]->getCalculationDetails()['multiplier']);
        self::assertSame('ОС 01/2026, т. 6', $charges[1]->getCalculationDetails()['unit_rule_decision_reference']);
    }
}
