<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Charge;
use App\Entity\FeePolicy;
use App\Entity\Fund;
use App\Entity\Payment;
use App\Entity\PaymentAllocation;
use App\Entity\Unit;
use App\Enum\FeeCategory;
use App\Enum\FeeDistribution;
use App\Enum\FundType;
use App\Enum\PaymentSource;
use App\Service\PaymentAllocator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PaymentAllocatorTest extends KernelTestCase
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

    public function testProposalAllocatesOldestOutstandingChargesFirstAndPartiallySettlesLastCharge(): void
    {
        [$unit, $july, $august, $september] = $this->persistThreeCharges();

        $proposal = (new PaymentAllocator($this->entityManager))->propose($unit, 2000);

        self::assertSame(2000, $proposal->getAllocatedCents());
        self::assertSame(0, $proposal->getUnallocatedCents());
        self::assertCount(3, $proposal->getAllocations());
        self::assertSame($july->getId(), $proposal->getAllocations()[0]->charge->getId());
        self::assertSame(850, $proposal->getAllocations()[0]->amountCents);
        self::assertSame($august->getId(), $proposal->getAllocations()[1]->charge->getId());
        self::assertSame(850, $proposal->getAllocations()[1]->amountCents);
        self::assertSame($september->getId(), $proposal->getAllocations()[2]->charge->getId());
        self::assertSame(300, $proposal->getAllocations()[2]->amountCents);
    }

    public function testProposalUsesOnlyOutstandingAmountAfterPreviousAllocation(): void
    {
        [$unit, $july, $august] = $this->persistTwoCharges();
        $previousPayment = Payment::post(
            $unit,
            500,
            PaymentSource::CASH,
            new DateTimeImmutable('2026-08-10 08:00:00 UTC'),
            new DateTimeImmutable('2026-08-10 08:05:00 UTC'),
        );
        $previousAllocation = PaymentAllocation::allocate(
            $previousPayment,
            $july,
            500,
            0,
            new DateTimeImmutable('2026-08-10 08:05:00 UTC'),
        );
        $this->entityManager->persist($previousPayment);
        $this->entityManager->persist($previousAllocation);
        $this->entityManager->flush();

        $proposal = (new PaymentAllocator($this->entityManager))->propose($unit, 1000);

        self::assertCount(2, $proposal->getAllocations());
        self::assertSame($july->getId(), $proposal->getAllocations()[0]->charge->getId());
        self::assertSame(350, $proposal->getAllocations()[0]->amountCents);
        self::assertSame($august->getId(), $proposal->getAllocations()[1]->charge->getId());
        self::assertSame(650, $proposal->getAllocations()[1]->amountCents);
    }

    public function testProposalLeavesRemainderUnallocatedWhenDebtIsSmallerThanPayment(): void
    {
        [$unit] = $this->persistTwoCharges();

        $proposal = (new PaymentAllocator($this->entityManager))->propose($unit, 2500);

        self::assertSame(1700, $proposal->getAllocatedCents());
        self::assertSame(800, $proposal->getUnallocatedCents());
    }

    public function testProposalRejectsNonPositivePaymentAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PaymentAllocator($this->entityManager))->propose(new Unit('12'), 0);
    }

    /** @return array{Unit, Charge, Charge} */
    private function persistTwoCharges(): array
    {
        $fund = new Fund('operating', 'Текуща поддръжка', FundType::OPERATING);
        $policy = $this->policy($fund);
        $unit = new Unit('12');
        $july = $this->charge($policy, $unit, '2026-07-01');
        $august = $this->charge($policy, $unit, '2026-08-01');

        foreach ([$fund, $policy, $unit, $july, $august] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return [$unit, $july, $august];
    }

    /** @return array{Unit, Charge, Charge, Charge} */
    private function persistThreeCharges(): array
    {
        [$unit, $july, $august] = $this->persistTwoCharges();
        $policy = $july->getPolicy();
        $september = $this->charge($policy, $unit, '2026-09-01');
        $this->entityManager->persist($september);
        $this->entityManager->flush();

        return [$unit, $july, $august, $september];
    }

    private function policy(Fund $fund): FeePolicy
    {
        return FeePolicy::create(
            'maintenance',
            'Месечна поддръжка',
            $fund,
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_UNIT,
            850,
            new DateTimeImmutable('2026-07-01'),
            'ОС 01/2026, т. 4',
        );
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
