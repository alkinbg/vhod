<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\BankAccount;
use App\Entity\BankStatementImport;
use App\Entity\BankTransaction;
use App\Entity\Payment;
use App\Entity\PaymentReconciliation;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\BankStatementFormat;
use App\Enum\PaymentSource;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FinanceBankOperationsControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private User $cashier;
    private Unit $unit;
    private BankAccount $account;

    protected function setUp(): void
    {
        self::createClient();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $tool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $person = new Person('Касиер', 'Тест', email: 'bank-ops@example.com');
        $this->cashier = new User($person, 'bank-ops@example.com', 'hash');
        $this->cashier->setRoles(['ROLE_CASHIER']);
        $this->unit = new Unit('12');
        $this->account = BankAccount::create('Основна сметка', 'BG35TEST00000000000000');

        foreach ([$person, $this->cashier, $this->unit, $this->account] as $entity) {
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

    public function testCashierCanLinkBankTransactionToExistingBankPaymentWithoutCreatingAnotherPayment(): void
    {
        $client = self::getClient();
        $transaction = $this->incomingTransaction('existing-link');
        $payment = Payment::post(
            $this->unit,
            2500,
            PaymentSource::BANK_TRANSFER,
            new DateTimeImmutable('2026-09-10'),
            new DateTimeImmutable('2026-09-10 09:00:00 UTC'),
            externalReference: 'existing-bank-payment',
        );
        $this->entityManager->persist($payment);
        $this->entityManager->flush();
        self::assertNotNull($transaction->getId());
        self::assertNotNull($payment->getId());

        $client->loginUser($this->cashier);
        $crawler = $client->request('GET', '/management/finance/operations/reconcile/'.$transaction->getId());
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Осчетоводи транзакцията')->form([
            'unit_id' => '',
            'payment_id' => (string) $payment->getId(),
            'note' => 'Свързано със съществуващ банков запис.',
        ]));

        self::assertResponseRedirects('/management/finance/operations');
        self::assertCount(1, $this->entityManager->getRepository(Payment::class)->findAll());
        $reconciliation = $this->entityManager->getRepository(PaymentReconciliation::class)->findOneBy([]);
        self::assertInstanceOf(PaymentReconciliation::class, $reconciliation);
        self::assertSame($payment->getId(), $reconciliation->getPayment()->getId());
    }

    public function testCashierCanImportCamt053ThroughManagementForm(): void
    {
        $client = self::getClient();
        self::assertNotNull($this->account->getId());

        $client->loginUser($this->cashier);
        $crawler = $client->request('GET', '/management/finance/operations/bank/import');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Импортирай извлечение')->form([
            'bank_account_id' => (string) $this->account->getId(),
        ]);
        $form['statement']->upload(__DIR__.'/../Fixtures/bank/camt053-basic.xml');
        $client->submit($form);

        self::assertResponseRedirects('/management/finance/operations');
        self::assertCount(1, $this->entityManager->getRepository(BankStatementImport::class)->findAll());
        self::assertCount(2, $this->entityManager->getRepository(BankTransaction::class)->findAll());
    }

    private function incomingTransaction(string $key): BankTransaction
    {
        $import = BankStatementImport::record(
            $this->account,
            BankStatementFormat::CAMT053,
            hash('sha256', 'import-'.$key),
            new DateTimeImmutable('2026-09-10 08:00:00 UTC'),
            1,
            statementReference: 'STMT-'.$key,
        );
        $transaction = BankTransaction::record(
            $this->account,
            $import,
            hash('sha256', 'transaction-'.$key),
            2500,
            new DateTimeImmutable('2026-09-10'),
            counterpartyName: 'Тестов платец',
            counterpartyIban: 'BG97FAKE00000000000001',
            remittanceInformation: 'Такса апартамент 12',
        );
        $this->entityManager->persist($import);
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return $transaction;
    }
}
