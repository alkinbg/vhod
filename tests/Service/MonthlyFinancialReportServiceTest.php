<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Charge;
use App\Entity\Expense;
use App\Entity\ExpenseReversal;
use App\Entity\ExternalIncome;
use App\Entity\ExternalIncomeReversal;
use App\Entity\FeePolicy;
use App\Entity\Fund;
use App\Entity\Payment;
use App\Entity\PaymentAllocation;
use App\Entity\PaymentReversal;
use App\Entity\Unit;
use App\Enum\ExpenseCategory;
use App\Enum\ExternalIncomeCategory;
use App\Enum\FeeCategory;
use App\Enum\FeeDistribution;
use App\Enum\FundType;
use App\Enum\PaymentSource;
use App\Service\MonthlyFinancialReportService;
use App\Value\FinancialReportLine;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MonthlyFinancialReportServiceTest extends KernelTestCase
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

    public function testSeptemberReportUsesSofiaCashMonthAndPreservesUnallocatedCash(): void
    {
        $fixture = $this->persistFixture();
        $report = (new MonthlyFinancialReportService($this->entityManager))->build(new DateTimeImmutable('2026-09-15 12:00:00 UTC'));

        self::assertSame(6000, $this->amountFor($report->getIncomeLines(), 'resident_management_maintenance'));
        self::assertSame(3000, $this->amountFor($report->getIncomeLines(), 'repair_renovation_fund'));
        self::assertSame(2000, $this->amountFor($report->getIncomeLines(), 'common_part_rent'));
        self::assertSame(1000, $this->amountFor($report->getIncomeLines(), 'other'));
        self::assertSame(12000, $report->getTotalIncomeCents());
        self::assertSame(4280, $this->amountFor($report->getExpenseLines(), ExpenseCategory::COMMON_ELECTRICITY->value));
        self::assertSame(4280, $report->getTotalExpenseCents());
        self::assertSame(7720, $report->getNetCents());

        self::assertSame('2026-09-01', $report->getMonth()->format('Y-m-d'));
        self::assertSame('Europe/Sofia', $report->getMonth()->getTimezone()->getName());
        self::assertNotNull($fixture['payment']->getId());
    }

    public function testLaterMonthReversalsOffsetOriginalCategoriesWithoutMutatingSeptember(): void
    {
        $this->persistFixture();
        $report = (new MonthlyFinancialReportService($this->entityManager))->build(new DateTimeImmutable('2026-10-20 12:00:00 Europe/Sofia'));

        self::assertSame(-6000, $this->amountFor($report->getIncomeLines(), 'resident_management_maintenance'));
        self::assertSame(-3000, $this->amountFor($report->getIncomeLines(), 'repair_renovation_fund'));
        self::assertSame(-2000, $this->amountFor($report->getIncomeLines(), 'common_part_rent'));
        self::assertSame(-1000, $this->amountFor($report->getIncomeLines(), 'other'));
        self::assertSame(-12000, $report->getTotalIncomeCents());
        self::assertSame(-4280, $this->amountFor($report->getExpenseLines(), ExpenseCategory::COMMON_ELECTRICITY->value));
        self::assertSame(-4280, $report->getTotalExpenseCents());
        self::assertSame(-7720, $report->getNetCents());
    }

    public function testExternalOfficialIncomeRowsKeepTheirOwnCategories(): void
    {
        $fund = new Fund('operating', 'Управление', FundType::OPERATING);
        $this->entityManager->persist($fund);
        foreach ([
            ExternalIncomeCategory::PUBLIC_FUNDING_SUBSIDY => 50000,
            ExternalIncomeCategory::DONATION => 1500,
            ExternalIncomeCategory::RENEWABLE_ENERGY => 2600,
        ] as $category => $amount) {
            // Enum objects cannot be array keys, so this branch is intentionally unreachable.
        }

        $income = [
            ExternalIncome::record($fund, ExternalIncomeCategory::PUBLIC_FUNDING_SUBSIDY, 50000, new DateTimeImmutable('2026-09-10 10:00:00 Europe/Sofia'), new DateTimeImmutable('2026-09-10 10:01:00 Europe/Sofia'), 'Субсидия'),
            ExternalIncome::record($fund, ExternalIncomeCategory::DONATION, 1500, new DateTimeImmutable('2026-09-11 10:00:00 Europe/Sofia'), new DateTimeImmutable('2026-09-11 10:01:00 Europe/Sofia'), 'Дарение'),
            ExternalIncome::record($fund, ExternalIncomeCategory::RENEWABLE_ENERGY, 2600, new DateTimeImmutable('2026-09-12 10:00:00 Europe/Sofia'), new DateTimeImmutable('2026-09-12 10:01:00 Europe/Sofia'), 'ВЕИ'),
        ];
        foreach ($income as $entry) {
            $this->entityManager->persist($entry);
        }
        $this->entityManager->flush();

        $report = (new MonthlyFinancialReportService($this->entityManager))->build(new DateTimeImmutable('2026-09-01 Europe/Sofia'));
        self::assertSame(50000, $this->amountFor($report->getIncomeLines(), ExternalIncomeCategory::PUBLIC_FUNDING_SUBSIDY->value));
        self::assertSame(1500, $this->amountFor($report->getIncomeLines(), ExternalIncomeCategory::DONATION->value));
        self::assertSame(2600, $this->amountFor($report->getIncomeLines(), ExternalIncomeCategory::RENEWABLE_ENERGY->value));
    }

    /** @return array{payment: Payment} */
    private function persistFixture(): array
    {
        $operating = new Fund('operating', 'Управление и поддръжка', FundType::OPERATING);
        $repair = new Fund('repair', 'Ремонт и обновяване', FundType::REPAIR_RENOVATION);
        $managementPolicy = FeePolicy::create(
            'management',
            'Управление и поддръжка',
            $operating,
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_UNIT,
            6000,
            new DateTimeImmutable('2026-09-01'),
            'ОС 01/2026, т. 1',
        );
        $repairPolicy = FeePolicy::create(
            'repair',
            'Фонд Ремонт и обновяване',
            $repair,
            FeeCategory::REPAIR_RENOVATION,
            FeeDistribution::IDEAL_PARTS,
            3000,
            new DateTimeImmutable('2026-09-01'),
            'ОС 01/2026, т. 2',
            statutoryMinimumConfirmed: true,
        );
        $unit = new Unit('12');
        $managementCharge = Charge::post($managementPolicy, $unit, new DateTimeImmutable('2026-09-01'), '1.0000', 6000, 6000, ['distribution' => 'per_unit'], new DateTimeImmutable('2026-09-01 04:00:00 UTC'));
        $repairCharge = Charge::post($repairPolicy, $unit, new DateTimeImmutable('2026-09-01'), '1.0000', 3000, 3000, ['distribution' => 'ideal_parts'], new DateTimeImmutable('2026-09-01 04:00:00 UTC'));

        $payment = Payment::post(
            $unit,
            10000,
            PaymentSource::BANK_TRANSFER,
            new DateTimeImmutable('2026-08-31 21:30:00 UTC'),
            new DateTimeImmutable('2026-08-31 21:35:00 UTC'),
            externalReference: 'report-payment-1',
        );
        $managementAllocation = PaymentAllocation::allocate($payment, $managementCharge, 6000, 0, new DateTimeImmutable('2026-08-31 21:35:00 UTC'));
        $repairAllocation = PaymentAllocation::allocate($payment, $repairCharge, 3000, 1, new DateTimeImmutable('2026-08-31 21:35:00 UTC'));
        $paymentReversal = PaymentReversal::record($payment, 10000, 'Корекция.', new DateTimeImmutable('2026-09-30 21:10:00 UTC'));

        $outside = Payment::post(
            $unit,
            777,
            PaymentSource::CASH,
            new DateTimeImmutable('2026-08-31 20:59:59 UTC'),
            new DateTimeImmutable('2026-08-31 20:59:59 UTC'),
            externalReference: 'outside-sofia-september',
        );

        $rent = ExternalIncome::record($operating, ExternalIncomeCategory::COMMON_PART_RENT, 2000, new DateTimeImmutable('2026-09-02 10:00:00 Europe/Sofia'), new DateTimeImmutable('2026-09-02 10:01:00 Europe/Sofia'), 'Наем обща част');
        $rentReversal = ExternalIncomeReversal::record($rent, 2000, 'Корекция.', new DateTimeImmutable('2026-10-02 10:00:00 Europe/Sofia'));

        $expense = Expense::post($operating, ExpenseCategory::COMMON_ELECTRICITY, 4280, new DateTimeImmutable('2026-09-05 10:00:00 Europe/Sofia'), new DateTimeImmutable('2026-09-05 10:01:00 Europe/Sofia'), 'Електроенергия общи части');
        $expenseReversal = ExpenseReversal::record($expense, 4280, 'Корекция.', new DateTimeImmutable('2026-10-02 11:00:00 Europe/Sofia'));

        foreach ([
            $operating,
            $repair,
            $managementPolicy,
            $repairPolicy,
            $unit,
            $managementCharge,
            $repairCharge,
            $payment,
            $managementAllocation,
            $repairAllocation,
            $paymentReversal,
            $outside,
            $rent,
            $rentReversal,
            $expense,
            $expenseReversal,
        ] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return ['payment' => $payment];
    }

    /** @param list<FinancialReportLine> $lines */
    private function amountFor(array $lines, string $code): int
    {
        foreach ($lines as $line) {
            if ($line->getCode() === $code) {
                return $line->getAmountCents();
            }
        }

        return 0;
    }
}
