<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\BudgetLine;
use App\Entity\Expense;
use App\Entity\ExpenseReversal;
use App\Entity\Fund;
use App\Enum\ExpenseCategory;
use App\Enum\FundType;
use App\Service\BudgetActualService;
use App\Value\BudgetActualLine;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BudgetActualServiceTest extends KernelTestCase
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

    public function testBuildComparesAnnualBudgetWithSignedYearToDateActuals(): void
    {
        $fund = new Fund('operating', 'Управление', FundType::OPERATING);
        $budget = BudgetLine::plan(2026, $fund, ExpenseCategory::COMMON_ELECTRICITY, 120000, 'ОС 02/2026', new DateTimeImmutable('2026-01-10 10:00:00 UTC'));
        $first = Expense::post($fund, ExpenseCategory::COMMON_ELECTRICITY, 40000, new DateTimeImmutable('2026-09-01 10:00:00 Europe/Sofia'), new DateTimeImmutable('2026-09-01 10:01:00 Europe/Sofia'), 'Ток');
        $second = Expense::post($fund, ExpenseCategory::COMMON_ELECTRICITY, 10000, new DateTimeImmutable('2026-09-10 10:00:00 Europe/Sofia'), new DateTimeImmutable('2026-09-10 10:01:00 Europe/Sofia'), 'Ток');
        $reversal = ExpenseReversal::record($first, 40000, 'Корекция', new DateTimeImmutable('2026-10-01 10:00:00 Europe/Sofia'));

        foreach ([$fund, $budget, $first, $second, $reversal] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $service = new BudgetActualService($this->entityManager);
        $september = $service->build(2026, new DateTimeImmutable('2026-09-30 12:00:00 Europe/Sofia'));
        $line = $this->find($september, 'operating', ExpenseCategory::COMMON_ELECTRICITY);
        self::assertSame(120000, $line->getBudgetCents());
        self::assertSame(50000, $line->getActualCents());
        self::assertSame(70000, $line->getRemainingCents());

        $october = $service->build(2026, new DateTimeImmutable('2026-10-31 12:00:00 Europe/Sofia'));
        $line = $this->find($october, 'operating', ExpenseCategory::COMMON_ELECTRICITY);
        self::assertSame(10000, $line->getActualCents());
        self::assertSame(110000, $line->getRemainingCents());
    }

    public function testActualWithoutBudgetStillAppears(): void
    {
        $fund = new Fund('operating', 'Управление', FundType::OPERATING);
        $expense = Expense::post($fund, ExpenseCategory::BANK_FEES, 500, new DateTimeImmutable('2026-09-05 10:00:00 Europe/Sofia'), new DateTimeImmutable('2026-09-05 10:01:00 Europe/Sofia'), 'Банкова такса');
        $this->entityManager->persist($fund);
        $this->entityManager->persist($expense);
        $this->entityManager->flush();

        $lines = (new BudgetActualService($this->entityManager))->build(2026, new DateTimeImmutable('2026-09-30 Europe/Sofia'));
        $line = $this->find($lines, 'operating', ExpenseCategory::BANK_FEES);

        self::assertSame(0, $line->getBudgetCents());
        self::assertSame(500, $line->getActualCents());
        self::assertSame(-500, $line->getRemainingCents());
    }

    /** @param list<BudgetActualLine> $lines */
    private function find(array $lines, string $fundCode, ExpenseCategory $category): BudgetActualLine
    {
        foreach ($lines as $line) {
            if ($line->getFund()->getCode() === $fundCode && $line->getCategory() === $category) {
                return $line;
            }
        }

        self::fail('Budget/actual line not found.');
    }
}
