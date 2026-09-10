<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\BudgetLine;
use App\Entity\Expense;
use App\Entity\ExpenseReversal;
use App\Entity\ExternalIncome;
use App\Entity\ExternalIncomeReversal;
use App\Entity\Fund;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\ExpenseCategory;
use App\Enum\ExternalIncomeCategory;
use App\Enum\FundType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FinanceManagementControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $resident;
    private User $manager;
    private User $cashier;
    private User $controller;
    private User $admin;
    private Fund $operatingFund;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $tool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->resident = $this->user('resident@example.com', []);
        $this->manager = $this->user('manager@example.com', ['ROLE_MANAGER']);
        $this->cashier = $this->user('cashier@example.com', ['ROLE_CASHIER']);
        $this->controller = $this->user('controller@example.com', ['ROLE_CONTROLLER']);
        $this->admin = $this->user('admin@example.com', ['ROLE_ADMIN']);
        $this->operatingFund = new Fund('operating', 'Управление и поддръжка', FundType::OPERATING);

        foreach ([$this->resident, $this->manager, $this->cashier, $this->controller, $this->admin] as $user) {
            $this->entityManager->persist($user->getPerson());
            $this->entityManager->persist($user);
        }
        $this->entityManager->persist($this->operatingFund);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function testResidentCannotReadManagementFinance(): void
    {
        $this->client->loginUser($this->resident);
        $this->client->request('GET', '/management/finance');

        self::assertResponseStatusCodeSame(403);
    }

    public function testManagementRolesCanReadFinanceDashboard(): void
    {
        foreach ([$this->manager, $this->cashier, $this->controller, $this->admin] as $user) {
            $this->client->loginUser($user);
            $this->client->request('GET', '/management/finance?month=2026-09');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Финанси');
        }
    }

    public function testControllerIsReadOnlyAndCannotOpenExpenseForm(): void
    {
        $this->client->loginUser($this->controller);
        $this->client->request('GET', '/management/finance/expense/new');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCashierCanPostExpenseWithDecisionReference(): void
    {
        $this->client->loginUser($this->cashier);
        $crawler = $this->client->request('GET', '/management/finance/expense/new');
        self::assertResponseIsSuccessful();

        $fundId = $this->operatingFund->getId();
        self::assertNotNull($fundId);
        $form = $crawler->selectButton('Запиши разход')->form([
            'fund_id' => (string) $fundId,
            'category' => ExpenseCategory::COMMON_ELECTRICITY->value,
            'amount' => '42,80',
            'paid_at' => '2026-09-05',
            'description' => 'Електроенергия общи части',
            'payee' => 'Електроснабдяване',
            'document_reference' => 'INV-001',
            'decision_reference' => 'ОС 02/2026, т. 5',
            'note' => '',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/management/finance');
        $expenses = $this->entityManager->getRepository(Expense::class)->findAll();
        self::assertCount(1, $expenses);
        self::assertSame(4280, $expenses[0]->getAmountCents());
        self::assertSame('ОС 02/2026, т. 5', $expenses[0]->getDecisionReference());
    }

    public function testCashierCanPostExternalIncome(): void
    {
        $this->client->loginUser($this->cashier);
        $crawler = $this->client->request('GET', '/management/finance/income/new');
        self::assertResponseIsSuccessful();

        $fundId = $this->operatingFund->getId();
        self::assertNotNull($fundId);
        $form = $crawler->selectButton('Запиши приход')->form([
            'fund_id' => (string) $fundId,
            'category' => ExternalIncomeCategory::COMMON_PART_RENT->value,
            'amount' => '25.00',
            'received_at' => '2026-09-06',
            'description' => 'Наем на обща част',
            'document_reference' => 'Договор 1/2026',
            'note' => '',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/management/finance');
        $income = $this->entityManager->getRepository(ExternalIncome::class)->findAll();
        self::assertCount(1, $income);
        self::assertSame(2500, $income[0]->getAmountCents());
    }

    public function testCashierCanReverseExpenseAndIncomeWithoutMutatingOriginalRows(): void
    {
        $expense = Expense::post(
            $this->operatingFund,
            ExpenseCategory::COMMON_ELECTRICITY,
            4280,
            new DateTimeImmutable('2026-09-05 Europe/Sofia'),
            new DateTimeImmutable('2026-09-05 10:00:00 Europe/Sofia'),
            'Електроенергия общи части',
        );
        $income = ExternalIncome::record(
            $this->operatingFund,
            ExternalIncomeCategory::COMMON_PART_RENT,
            2500,
            new DateTimeImmutable('2026-09-06 Europe/Sofia'),
            new DateTimeImmutable('2026-09-06 10:00:00 Europe/Sofia'),
            'Наем на обща част',
        );
        $this->entityManager->persist($expense);
        $this->entityManager->persist($income);
        $this->entityManager->flush();
        self::assertNotNull($expense->getId());
        self::assertNotNull($income->getId());

        $this->client->loginUser($this->cashier);

        $crawler = $this->client->request('GET', '/management/finance/expense/'.$expense->getId().'/reverse');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Сторнирай')->form([
            'reason' => 'Грешно въведен разход.',
        ]));
        self::assertResponseRedirects('/management/finance');

        $crawler = $this->client->request('GET', '/management/finance/income/'.$income->getId().'/reverse');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Сторнирай')->form([
            'reason' => 'Грешно въведен приход.',
        ]));
        self::assertResponseRedirects('/management/finance');

        $expenseReversals = $this->entityManager->getRepository(ExpenseReversal::class)->findAll();
        $incomeReversals = $this->entityManager->getRepository(ExternalIncomeReversal::class)->findAll();
        self::assertCount(1, $expenseReversals);
        self::assertCount(1, $incomeReversals);
        self::assertSame('Грешно въведен разход.', $expenseReversals[0]->getReason());
        self::assertSame('Грешно въведен приход.', $incomeReversals[0]->getReason());
        self::assertCount(1, $this->entityManager->getRepository(Expense::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(ExternalIncome::class)->findAll());
        self::assertSame(4280, $expense->getAmountCents());
        self::assertSame(2500, $income->getAmountCents());
    }

    public function testReversalRequiresFinanceWriterAndValidCsrf(): void
    {
        $expense = Expense::post(
            $this->operatingFund,
            ExpenseCategory::OTHER,
            100,
            new DateTimeImmutable('2026-09-08 Europe/Sofia'),
            new DateTimeImmutable('2026-09-08 10:00:00 Europe/Sofia'),
            'Тестов разход',
        );
        $this->entityManager->persist($expense);
        $this->entityManager->flush();
        self::assertNotNull($expense->getId());

        $this->client->loginUser($this->controller);
        $this->client->request('GET', '/management/finance/expense/'.$expense->getId().'/reverse');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->cashier);
        $this->client->request('POST', '/management/finance/expense/'.$expense->getId().'/reverse', [
            '_token' => 'invalid',
            'reason' => 'Не трябва да се приложи.',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->entityManager->getRepository(ExpenseReversal::class)->findAll());
    }

    public function testCashierCannotCreateBudgetButManagerCan(): void
    {
        $this->client->loginUser($this->cashier);
        $this->client->request('GET', '/management/finance/budget/new');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->manager);
        $crawler = $this->client->request('GET', '/management/finance/budget/new');
        self::assertResponseIsSuccessful();

        $fundId = $this->operatingFund->getId();
        self::assertNotNull($fundId);
        $form = $crawler->selectButton('Запиши бюджет')->form([
            'year' => '2026',
            'fund_id' => (string) $fundId,
            'category' => ExpenseCategory::COMMON_ELECTRICITY->value,
            'amount' => '1200.00',
            'decision_reference' => 'ОС 02/2026, т. 5',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/management/finance');
        $budgets = $this->entityManager->getRepository(BudgetLine::class)->findAll();
        self::assertCount(1, $budgets);
        self::assertSame(120000, $budgets[0]->getAmountCents());
    }

    public function testWriteRequiresValidCsrfToken(): void
    {
        $this->client->loginUser($this->manager);
        $fundId = $this->operatingFund->getId();
        self::assertNotNull($fundId);

        $this->client->request('POST', '/management/finance/expense/new', [
            '_token' => 'invalid',
            'fund_id' => (string) $fundId,
            'category' => ExpenseCategory::OTHER->value,
            'amount' => '1.00',
            'paid_at' => '2026-09-08',
            'description' => 'Тест',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->entityManager->getRepository(Expense::class)->findAll());
    }

    public function testPrintableMonthlyReportShowsReportAndBudgetActual(): void
    {
        $budget = BudgetLine::plan(2026, $this->operatingFund, ExpenseCategory::COMMON_ELECTRICITY, 120000, 'ОС 02/2026', new DateTimeImmutable('2026-01-02 UTC'));
        $expense = Expense::post($this->operatingFund, ExpenseCategory::COMMON_ELECTRICITY, 4280, new DateTimeImmutable('2026-09-05 Europe/Sofia'), new DateTimeImmutable('2026-09-05 10:00:00 Europe/Sofia'), 'Електроенергия');
        $this->entityManager->persist($budget);
        $this->entityManager->persist($expense);
        $this->entityManager->flush();

        $this->client->loginUser($this->controller);
        $crawler = $this->client->request('GET', '/management/finance/report/2026/9');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Месечен финансов отчет');
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('42.80 €', $text);
        self::assertStringContainsString('1200.00 €', $text);
    }

    private function user(string $email, array $roles): User
    {
        $person = new Person('Тест', ucfirst(strstr($email, '@', true) ?: 'User'), email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);

        return $user;
    }
}
