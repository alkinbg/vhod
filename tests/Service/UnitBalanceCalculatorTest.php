<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Charge;
use App\Entity\FeePolicy;
use App\Entity\Fund;
use App\Entity\Payment;
use App\Entity\PaymentAllocation;
use App\Entity\PaymentReversal;
use App\Entity\Unit;
use App\Enum\FeeCategory;
use App\Enum\FeeDistribution;
use App\Enum\FundType;
use App\Enum\PaymentSource;
use App\Service\UnitBalanceCalculator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UnitBalanceCalculatorTest extends KernelTestCase
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

    public function testNetBalanceIncludesUnallocatedPaymentCredit(): void
    {
        [$unit, $july, $august] = $this->persistTwoCharges();
        $payment = Payment::post(
            $unit,
            2000,
            PaymentSource::BANK_TRANSFER,
            new DateTimeImmutable('2026-09-08 07:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 07:05:00 UTC'),
        );
        $this->entityManager->persist($payment);
        $this->entityManager->persist(PaymentAllocation::allocate($payment, $july, 850, 0, new DateTimeImmutable('2026-09-08 07:05:00 UTC')));
        $this->entityManager->persist(PaymentAllocation::allocate($payment, $august, 850, 1, new DateTimeImmutable('2026-09-08 07:05:00 UTC')));
        $this->entityManager->flush();

        self::assertSame(-300, (new UnitBalanceCalculator($this->entityManager))->netBalanceCents($unit));
    }

    public function testReversedPaymentNoLongerReducesUnitBalance(): void
    {
        [$unit, $july, $august] = $this->persistTwoCharges();
        $payment = Payment::post(
            $unit,
            1700,
            PaymentSource::CASH,
            new DateTimeImmutable('2026-09-08 07:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 07:05:00 UTC'),
        );
        $this->entityManager->persist($payment);
        $this->entityManager->persist(PaymentAllocation::allocate($payment, $july, 850, 0, new DateTimeImmutable('2026-09-08 07:05:00 UTC')));
        $this->entityManager->persist(PaymentAllocation::allocate($payment, $august, 850, 1, new DateTimeImmutable('2026-09-08 07:05:00 UTC')));
        $this->entityManager->flush();

        self::assertSame(0, (new UnitBalanceCalculator($this->entityManager))->netBalanceCents($unit));

        $this->entityManager->persist(PaymentReversal::record(
            $payment,
            1700,
            'Корекция.',
            new DateTimeImmutable('2026-09-08 08:00:00 UTC'),
        ));
        $this->entityManager->flush();

        self::assertSame(1700, (new UnitBalanceCalculator($this->entityManager))->netBalanceCents($unit));
    }

    /** @return array{Unit, Charge, Charge} */
    private function persistTwoCharges(): array
    {
        $fund = new Fund('operating', 'Текуща поддръжка', FundType::OPERATING);
        $policy = FeePolicy::create(
            'maintenance',
            'Месечна поддръжка',
            $fund,
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_UNIT,
            850,
            new DateTimeImmutable('2026-07-01'),
            'ОС 01/2026, т. 4',
        );
        $unit = new Unit('12');
        $july = $this->charge($policy, $unit, '2026-07-01');
        $august = $this->charge($policy, $unit, '2026-08-01');

        foreach ([$fund, $policy, $unit, $july, $august] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return [$unit, $july, $august];
    }

    private function charge(FeePolicy $policy, Unit $unit, string $month): Charge
    {
        return Charge::post(
            $policy,
            $unit,
            new DateTimeImmutable($month),
            '1.0000',
            850,
            850,
            ['distribution' => 'per_unit'],
            new DateTimeImmutable('2026-09-08 04:00:00 UTC'),
        );
    }
}
