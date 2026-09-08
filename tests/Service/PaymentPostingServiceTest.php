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
use App\Service\PaymentPostingService;
use App\Value\PaymentAllocationProposal;
use App\Value\ProposedAllocation;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PaymentPostingServiceTest extends KernelTestCase
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

    public function testPostAutomaticallyAllocatesOldestChargesAndPersistsOneAtomicOperation(): void
    {
        [$unit] = $this->persistCharges('2026-07-01', '2026-08-01', '2026-09-01');
        $result = $this->service()->post(
            $unit,
            2000,
            PaymentSource::BANK_TRANSFER,
            new DateTimeImmutable('2026-09-08 07:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 07:05:00 UTC'),
            'Септември',
            'bank-001',
        );

        self::assertSame(2000, $result->allocatedCents);
        self::assertSame(0, $result->unallocatedCents);
        self::assertSame(2000, $result->payment->getAmountCents());
        self::assertCount(1, $this->entityManager->getRepository(Payment::class)->findAll());

        $allocations = $this->entityManager->getRepository(PaymentAllocation::class)->findBy([], ['position' => 'ASC']);
        self::assertCount(3, $allocations);
        self::assertSame(850, $allocations[0]->getAmountCents());
        self::assertSame(850, $allocations[1]->getAmountCents());
        self::assertSame(300, $allocations[2]->getAmountCents());
    }

    public function testManagerProposalCanOverrideDefaultOrderBeforePosting(): void
    {
        [$unit, $july, $august] = $this->persistCharges('2026-07-01', '2026-08-01');
        $proposal = new PaymentAllocationProposal(1000, [
            new ProposedAllocation($august, 850),
        ]);

        $result = $this->service()->post(
            $unit,
            1000,
            PaymentSource::CASH,
            new DateTimeImmutable('2026-09-08 07:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 07:05:00 UTC'),
            explicitProposal: $proposal,
        );

        self::assertSame(850, $result->allocatedCents);
        self::assertSame(150, $result->unallocatedCents);
        $allocation = $this->entityManager->getRepository(PaymentAllocation::class)->findOneBy([]);
        self::assertInstanceOf(PaymentAllocation::class, $allocation);
        self::assertSame($august->getId(), $allocation->getCharge()->getId());
        self::assertNotSame($july->getId(), $allocation->getCharge()->getId());
    }

    public function testInvalidCrossUnitOverrideRollsBackWithoutPayment(): void
    {
        [$unit, $charge] = $this->persistCharges('2026-07-01');
        $otherUnit = new Unit('13');
        $this->entityManager->persist($otherUnit);
        $this->entityManager->flush();
        $proposal = new PaymentAllocationProposal(850, [new ProposedAllocation($charge, 850)]);

        try {
            $this->service()->post(
                $otherUnit,
                850,
                PaymentSource::CASH,
                new DateTimeImmutable('2026-09-08 07:00:00 UTC'),
                new DateTimeImmutable('2026-09-08 07:05:00 UTC'),
                explicitProposal: $proposal,
            );
            self::fail('Expected cross-unit proposal to be rejected.');
        } catch (DomainException $exception) {
            self::assertSame('Allocation proposal contains a charge from another unit.', $exception->getMessage());
        }

        self::assertCount(0, $this->entityManager->getRepository(Payment::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(PaymentAllocation::class)->findAll());
    }

    public function testOverrideCannotAllocateMoreThanChargeOutstanding(): void
    {
        [$unit, $charge] = $this->persistCharges('2026-07-01');
        $proposal = new PaymentAllocationProposal(900, [new ProposedAllocation($charge, 900)]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Allocation exceeds charge outstanding amount.');

        $this->service()->post(
            $unit,
            900,
            PaymentSource::CASH,
            new DateTimeImmutable('2026-09-08 07:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 07:05:00 UTC'),
            explicitProposal: $proposal,
        );
    }

    public function testPaymentWithNoDebtRemainsFullyUnallocatedCredit(): void
    {
        $unit = new Unit('12');
        $this->entityManager->persist($unit);
        $this->entityManager->flush();

        $result = $this->service()->post(
            $unit,
            500,
            PaymentSource::CASH,
            new DateTimeImmutable('2026-09-08 07:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 07:05:00 UTC'),
        );

        self::assertSame(0, $result->allocatedCents);
        self::assertSame(500, $result->unallocatedCents);
        self::assertCount(1, $this->entityManager->getRepository(Payment::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(PaymentAllocation::class)->findAll());
    }

    public function testExternalReferenceIsIdempotentAndReturnsExistingPayment(): void
    {
        [$unit] = $this->persistCharges('2026-07-01');
        $service = $this->service();

        $first = $service->post(
            $unit,
            850,
            PaymentSource::BANK_TRANSFER,
            new DateTimeImmutable('2026-09-08 07:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 07:05:00 UTC'),
            externalReference: 'bank-idempotent-1',
        );
        $second = $service->post(
            $unit,
            850,
            PaymentSource::BANK_TRANSFER,
            new DateTimeImmutable('2026-09-08 07:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 07:06:00 UTC'),
            externalReference: 'bank-idempotent-1',
        );

        self::assertSame($first->payment->getId(), $second->payment->getId());
        self::assertSame(850, $second->allocatedCents);
        self::assertSame(0, $second->unallocatedCents);
        self::assertCount(1, $this->entityManager->getRepository(Payment::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(PaymentAllocation::class)->findAll());
    }

    private function service(): PaymentPostingService
    {
        $allocator = new PaymentAllocator($this->entityManager);

        return new PaymentPostingService($this->entityManager, $allocator);
    }

    /** @return array{Unit, Charge, ...} */
    private function persistCharges(string ...$months): array
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
        $entities = [$fund, $policy, $unit];
        $charges = [];
        foreach ($months as $month) {
            $charge = Charge::post(
                $policy,
                $unit,
                new DateTimeImmutable($month),
                '1.0000',
                850,
                850,
                ['distribution' => 'per_unit'],
                new DateTimeImmutable('2026-09-08 04:00:00 UTC'),
            );
            $charges[] = $charge;
            $entities[] = $charge;
        }

        foreach ($entities as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return [$unit, ...$charges];
    }
}
