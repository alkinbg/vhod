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
use App\Service\PaymentAllocator;
use App\Service\PaymentReversalService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PaymentReversalServiceTest extends KernelTestCase
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

    public function testReversalRestoresAllocatedDebtWithoutMutatingOriginalRows(): void
    {
        [$unit, $charge, $payment, $allocation] = $this->persistFullyPaidCharge();

        $before = (new PaymentAllocator($this->entityManager))->propose($unit, 850);
        self::assertSame(0, $before->getAllocatedCents());
        self::assertSame(850, $before->getUnallocatedCents());

        $result = (new PaymentReversalService($this->entityManager))->reverse(
            $payment,
            'Грешно плащане.',
            new DateTimeImmutable('2026-09-08 08:00:00 UTC'),
        );

        self::assertSame($payment, $result->reversal->getPayment());
        self::assertSame(850, $result->restoredAllocatedCents);
        self::assertSame(0, $result->restoredUnallocatedCents);
        self::assertSame(850, $payment->getAmountCents());
        self::assertSame(850, $allocation->getAmountCents());
        self::assertCount(1, $this->entityManager->getRepository(Payment::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(PaymentAllocation::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(PaymentReversal::class)->findAll());

        $after = (new PaymentAllocator($this->entityManager))->propose($unit, 850);
        self::assertSame(850, $after->getAllocatedCents());
        self::assertSame(0, $after->getUnallocatedCents());
        self::assertSame($charge->getId(), $after->getAllocations()[0]->charge->getId());
    }

    public function testReversalMayOccurOnlyOnce(): void
    {
        [, , $payment] = $this->persistFullyPaidCharge();
        $service = new PaymentReversalService($this->entityManager);
        $service->reverse($payment, 'Първа корекция.', new DateTimeImmutable('2026-09-08 08:00:00 UTC'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Payment has already been reversed.');

        $service->reverse($payment, 'Втора корекция.', new DateTimeImmutable('2026-09-08 08:05:00 UTC'));
    }

    public function testReversalReportsUnallocatedCreditThatIsNeutralized(): void
    {
        $unit = new Unit('12');
        $payment = Payment::post(
            $unit,
            500,
            PaymentSource::CASH,
            new DateTimeImmutable('2026-09-08 07:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 07:05:00 UTC'),
        );
        $this->entityManager->persist($unit);
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        $result = (new PaymentReversalService($this->entityManager))->reverse(
            $payment,
            'Връщане на надплатена сума.',
            new DateTimeImmutable('2026-09-08 08:00:00 UTC'),
        );

        self::assertSame(0, $result->restoredAllocatedCents);
        self::assertSame(500, $result->restoredUnallocatedCents);
    }

    /** @return array{Unit, Charge, Payment, PaymentAllocation} */
    private function persistFullyPaidCharge(): array
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
        $charge = Charge::post(
            $policy,
            $unit,
            new DateTimeImmutable('2026-07-01'),
            '1.0000',
            850,
            850,
            ['distribution' => 'per_unit'],
            new DateTimeImmutable('2026-09-08 04:00:00 UTC'),
        );
        $payment = Payment::post(
            $unit,
            850,
            PaymentSource::CASH,
            new DateTimeImmutable('2026-09-08 07:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 07:05:00 UTC'),
        );
        $allocation = PaymentAllocation::allocate(
            $payment,
            $charge,
            850,
            0,
            new DateTimeImmutable('2026-09-08 07:05:00 UTC'),
        );

        foreach ([$fund, $policy, $unit, $charge, $payment, $allocation] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return [$unit, $charge, $payment, $allocation];
    }
}
