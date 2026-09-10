<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\BankAccount;
use App\Entity\BankStatementImport;
use App\Entity\BankTransaction;
use App\Entity\Charge;
use App\Entity\FeePolicy;
use App\Entity\Fund;
use App\Entity\Payment;
use App\Entity\PaymentReconciliation;
use App\Entity\PaymentReversal;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\BankStatementFormat;
use App\Enum\FeeCategory;
use App\Enum\FeeDistribution;
use App\Enum\FundType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FinanceOperationsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $cashier;
    private User $controller;
    private Unit $unit;

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

        $this->cashier = $this->user('cashier-ops@example.com', ['ROLE_CASHIER']);
        $this->controller = $this->user('controller-ops@example.com', ['ROLE_CONTROLLER']);
        $this->unit = new Unit('12');
        $fund = new Fund('operating', 'Управление и поддръжка', FundType::OPERATING);
        $policy = FeePolicy::create(
            'maintenance',
            'Месечна поддръжка',
            $fund,
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_UNIT,
            2500,
            new DateTimeImmutable('2026-09-01'),
            'ОС 01/2026, т. 4',
        );

        foreach ([$this->cashier, $this->controller] as $user) {
            $this->entityManager->persist($user->getPerson());
            $this->entityManager->persist($user);
        }
        foreach ([$this->unit, $fund, $policy] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->isOpen()) {
            $this->entityManager->close();
        }
        parent::tearDown();
    }

    public function testCashierCanGenerateChargePostCashPaymentAndReverseIt(): void
    {
        $this->client->loginUser($this->cashier);

        $crawler = $this->client->request('GET', '/management/finance/operations');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Генерирай начисления')->form([
            'month' => '2026-09',
        ]));
        self::assertResponseRedirects('/management/finance/operations');
        self::assertCount(1, $this->entityManager->getRepository(Charge::class)->findAll());

        $crawler = $this->client->request('GET', '/management/finance/operations/payment/new');
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->unit->getId());
        $this->client->submit($crawler->selectButton('Запиши плащане')->form([
            'unit_id' => (string) $this->unit->getId(),
            'amount' => '25.00',
            'received_at' => '2026-09-10',
            'reference' => 'Каса 10.09.2026',
            'note' => '',
        ]));
        self::assertResponseRedirects('/management/finance/operations');

        $payment = $this->entityManager->getRepository(Payment::class)->findOneBy([]);
        self::assertInstanceOf(Payment::class, $payment);
        self::assertSame(2500, $payment->getAmountCents());
        self::assertNotNull($payment->getId());

        $crawler = $this->client->request('GET', '/management/finance/operations/payment/'.$payment->getId().'/reverse');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Сторнирай плащането')->form([
            'reason' => 'Дублирано касово плащане.',
        ]));
        self::assertResponseRedirects('/management/finance/operations');

        self::assertCount(1, $this->entityManager->getRepository(Payment::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(PaymentReversal::class)->findAll());
    }

    public function testCashierCanReconcileIncomingBankTransactionToUnit(): void
    {
        $transaction = $this->persistIncomingBankTransaction();
        self::assertNotNull($transaction->getId());
        self::assertNotNull($this->unit->getId());

        $this->client->loginUser($this->cashier);
        $crawler = $this->client->request('GET', '/management/finance/operations/reconcile/'.$transaction->getId());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Осчетоводи транзакцията')->form([
            'unit_id' => (string) $this->unit->getId(),
            'payment_id' => '',
            'note' => 'Ръчно разпознато плащане.',
        ]));
        self::assertResponseRedirects('/management/finance/operations');

        self::assertCount(1, $this->entityManager->getRepository(PaymentReconciliation::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(Payment::class)->findAll());
    }

    public function testControllerCannotMutateFinanceOperationsAndCsrfIsRequired(): void
    {
        $this->client->loginUser($this->controller);
        $this->client->request('GET', '/management/finance/operations/payment/new');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->cashier);
        $this->client->request('POST', '/management/finance/operations/generate-charges', [
            '_token' => 'invalid',
            'month' => '2026-09',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->entityManager->getRepository(Charge::class)->findAll());
    }

    private function persistIncomingBankTransaction(): BankTransaction
    {
        $account = BankAccount::create('Основна сметка', 'BG35TEST00000000000000');
        $import = BankStatementImport::record(
            $account,
            BankStatementFormat::CAMT053,
            hash('sha256', 'finance-operations-import'),
            new DateTimeImmutable('2026-09-10 08:00:00 UTC'),
            1,
            statementReference: 'OPS-001',
        );
        $transaction = BankTransaction::record(
            $account,
            $import,
            hash('sha256', 'finance-operations-transaction'),
            2500,
            new DateTimeImmutable('2026-09-10'),
            counterpartyName: 'Тестов платец',
            counterpartyIban: 'BG97FAKE00000000000001',
            remittanceInformation: 'Такса апартамент 12',
        );

        foreach ([$account, $import, $transaction] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return $transaction;
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles): User
    {
        $person = new Person('Тест', ucfirst(strstr($email, '@', true) ?: 'User'), email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);

        return $user;
    }
}
