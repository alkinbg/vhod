<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\BankAccount;
use App\Entity\BankCounterpartyMapping;
use App\Entity\BankStatementImport;
use App\Entity\BankTransaction;
use App\Entity\Payment;
use App\Entity\PaymentReconciliation;
use App\Entity\PaymentReversal;
use App\Entity\Unit;
use App\Enum\BankStatementFormat;
use App\Enum\PaymentSource;
use App\Enum\ReconciliationMethod;
use App\Service\BankReconciliationService;
use App\Service\PaymentAllocator;
use App\Service\PaymentPostingService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BankReconciliationServiceTest extends KernelTestCase
{
    private const ACCOUNT_IBAN = 'BG35TEST00000000000000';
    private const PAYER_IBAN = 'BG97FAKE00000000000001';

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
        if ($this->entityManager->isOpen()) {
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testManualReconciliationPostsExactlyOneBankPayment(): void
    {
        $transaction = $this->persistTransaction('a');
        $unit = $this->persistUnit('12');

        $reconciliation = $this->service()->reconcileToUnit(
            $transaction,
            $unit,
            new DateTimeImmutable('2026-09-08 08:30:00 Europe/Sofia'),
            '  Потвърдено ръчно.  ',
        );

        self::assertSame(ReconciliationMethod::MANUAL, $reconciliation->getMethod());
        self::assertSame(PaymentSource::BANK_TRANSFER, $reconciliation->getPayment()->getSource());
        self::assertSame($transaction->getAmountCents(), $reconciliation->getPayment()->getAmountCents());
        self::assertSame($unit->getId(), $reconciliation->getPayment()->getUnit()->getId());
        self::assertSame('bank:'.$transaction->getFingerprint(), $reconciliation->getPayment()->getExternalReference());
        self::assertSame('Потвърдено ръчно.', $reconciliation->getNote());
        self::assertCount(1, $this->entityManager->getRepository(Payment::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(PaymentReconciliation::class)->findAll());
    }

    public function testAutomaticReconciliationRequiresExactActivePayerMapping(): void
    {
        $transaction = $this->persistTransaction('b');
        $unit = $this->persistUnit('12');
        $this->entityManager->persist(BankCounterpartyMapping::create(
            self::PAYER_IBAN,
            $unit,
            new DateTimeImmutable('2026-09-08 05:00:00 UTC'),
        ));
        $this->entityManager->flush();

        $first = $this->service()->autoReconcile($transaction, new DateTimeImmutable('2026-09-08 05:30:00 UTC'));
        $second = $this->service()->autoReconcile($transaction, new DateTimeImmutable('2026-09-08 05:31:00 UTC'));

        self::assertInstanceOf(PaymentReconciliation::class, $first);
        self::assertSame(ReconciliationMethod::AUTOMATIC, $first->getMethod());
        self::assertSame($unit->getId(), $first->getPayment()->getUnit()->getId());
        self::assertNull($second);
        self::assertCount(1, $this->entityManager->getRepository(Payment::class)->findAll());
    }

    public function testAutomaticReconciliationNeverFallsBackToAmountOnly(): void
    {
        $transaction = $this->persistTransaction('c');
        $unrelatedUnit = $this->persistUnit('99');
        $this->entityManager->persist(Payment::post(
            $unrelatedUnit,
            $transaction->getAmountCents(),
            PaymentSource::BANK_TRANSFER,
            new DateTimeImmutable('2026-09-08 00:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 05:10:00 UTC'),
        ));
        $this->entityManager->flush();

        self::assertNull($this->service()->autoReconcile(
            $transaction,
            new DateTimeImmutable('2026-09-08 05:30:00 UTC'),
        ));
        self::assertCount(1, $this->entityManager->getRepository(Payment::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(PaymentReconciliation::class)->findAll());
    }

    public function testManualDebitReconciliationIsRejectedWithoutPayment(): void
    {
        $transaction = $this->persistTransaction('d', -3200);
        $unit = $this->persistUnit('12');

        try {
            $this->service()->reconcileToUnit($transaction, $unit, new DateTimeImmutable('2026-09-08 05:30:00 UTC'));
            self::fail('Expected outgoing transaction to be rejected.');
        } catch (DomainException $exception) {
            self::assertSame('Only incoming bank transactions can be reconciled.', $exception->getMessage());
        }

        self::assertCount(0, $this->entityManager->getRepository(Payment::class)->findAll());
    }

    public function testDuplicateManualReconciliationCannotDuplicateMoney(): void
    {
        $transaction = $this->persistTransaction('e');
        $unit = $this->persistUnit('12');
        $service = $this->service();
        $service->reconcileToUnit($transaction, $unit, new DateTimeImmutable('2026-09-08 05:30:00 UTC'));

        try {
            $service->reconcileToUnit($transaction, $unit, new DateTimeImmutable('2026-09-08 05:31:00 UTC'));
            self::fail('Expected duplicate reconciliation to be rejected.');
        } catch (DomainException $exception) {
            self::assertSame('Bank transaction is already reconciled.', $exception->getMessage());
        }

        self::assertCount(1, $this->entityManager->getRepository(Payment::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(PaymentReconciliation::class)->findAll());
    }

    public function testExplicitExistingPaymentLinkCreatesNoSecondPayment(): void
    {
        $transaction = $this->persistTransaction('f');
        $unit = $this->persistUnit('12');
        $payment = Payment::post(
            $unit,
            $transaction->getAmountCents(),
            PaymentSource::BANK_TRANSFER,
            $transaction->getBookingDate(),
            new DateTimeImmutable('2026-09-08 05:10:00 UTC'),
            externalReference: 'cashier-recorded-first',
        );
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        $reconciliation = $this->service()->linkExistingPayment(
            $transaction,
            $payment,
            new DateTimeImmutable('2026-09-08 05:30:00 UTC'),
            'Свързано след импорт.',
        );

        self::assertSame($payment->getId(), $reconciliation->getPayment()->getId());
        self::assertCount(1, $this->entityManager->getRepository(Payment::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(PaymentReconciliation::class)->findAll());
    }

    public function testExistingPaymentCannotBeReusedForAnotherBankTransaction(): void
    {
        $firstTransaction = $this->persistTransaction('1');
        $secondTransaction = $this->persistTransaction('2');
        $unit = $this->persistUnit('12');
        $payment = Payment::post(
            $unit,
            $firstTransaction->getAmountCents(),
            PaymentSource::BANK_TRANSFER,
            $firstTransaction->getBookingDate(),
            new DateTimeImmutable('2026-09-08 05:10:00 UTC'),
        );
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        $service = $this->service();
        $service->linkExistingPayment($firstTransaction, $payment, new DateTimeImmutable('2026-09-08 05:20:00 UTC'));

        try {
            $service->linkExistingPayment($secondTransaction, $payment, new DateTimeImmutable('2026-09-08 05:30:00 UTC'));
            self::fail('Expected payment reuse to be rejected.');
        } catch (DomainException $exception) {
            self::assertSame('Payment is already reconciled.', $exception->getMessage());
        }

        self::assertCount(1, $this->entityManager->getRepository(Payment::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(PaymentReconciliation::class)->findAll());
    }

    public function testReconciliationFailureRollsBackPaymentPostedByNestedService(): void
    {
        $transaction = $this->persistTransaction('3');
        $unit = $this->persistUnit('12');
        $connection = $this->entityManager->getConnection();
        $listener = new class($this->entityManager) {
            public function __construct(private EntityManagerInterface $entityManager)
            {
            }

            public function onFlush(): void
            {
                foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof PaymentReconciliation) {
                        throw new RuntimeException('Forced reconciliation persistence failure.');
                    }
                }
            }
        };
        $this->entityManager->getEventManager()->addEventListener([Events::onFlush], $listener);

        try {
            $this->service()->reconcileToUnit($transaction, $unit, new DateTimeImmutable('2026-09-08 05:30:00 UTC'));
            self::fail('Expected forced reconciliation persistence failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Forced reconciliation persistence failure.', $exception->getMessage());
        }

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM payment'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM payment_reconciliation'));
    }

    public function testReversalDoesNotEraseHistoricalReconciliation(): void
    {
        $transaction = $this->persistTransaction('4');
        $unit = $this->persistUnit('12');
        $reconciliation = $this->service()->reconcileToUnit(
            $transaction,
            $unit,
            new DateTimeImmutable('2026-09-08 05:30:00 UTC'),
        );
        $reversal = PaymentReversal::record(
            $reconciliation->getPayment(),
            $reconciliation->getPayment()->getAmountCents(),
            'Дублирано плащане.',
            new DateTimeImmutable('2026-09-08 06:00:00 UTC'),
        );
        $this->entityManager->persist($reversal);
        $this->entityManager->flush();

        $stored = $this->entityManager->getRepository(PaymentReconciliation::class)->find($reconciliation->getId());
        self::assertInstanceOf(PaymentReconciliation::class, $stored);
        self::assertSame($reversal->getPayment()->getId(), $stored->getPayment()->getId());
        self::assertCount(1, $this->entityManager->getRepository(PaymentReversal::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(PaymentReconciliation::class)->findAll());
    }

    private function service(): BankReconciliationService
    {
        $allocator = new PaymentAllocator($this->entityManager);
        $postingService = new PaymentPostingService($this->entityManager, $allocator);

        return new BankReconciliationService($this->entityManager, $postingService);
    }

    private function persistUnit(string $designation): Unit
    {
        $unit = new Unit($designation);
        $this->entityManager->persist($unit);
        $this->entityManager->flush();

        return $unit;
    }

    private function persistTransaction(
        string $fingerprintCharacter,
        int $amountCents = 12550,
        ?string $counterpartyIban = self::PAYER_IBAN,
    ): BankTransaction {
        $account = $this->entityManager->getRepository(BankAccount::class)->findOneBy([]);
        if (!$account instanceof BankAccount) {
            $account = BankAccount::create('Основна сметка', self::ACCOUNT_IBAN);
            $this->entityManager->persist($account);
        }

        $fingerprint = str_repeat($fingerprintCharacter, 64);
        $import = BankStatementImport::record(
            $account,
            BankStatementFormat::CAMT053,
            hash('sha256', 'import-'.$fingerprint),
            new DateTimeImmutable('2026-09-08 05:00:00 UTC'),
            1,
            statementReference: 'STMT-'.$fingerprintCharacter,
        );
        $transaction = BankTransaction::record(
            $account,
            $import,
            $fingerprint,
            $amountCents,
            new DateTimeImmutable('2026-09-08'),
            bankTransactionId: 'TX-'.$fingerprintCharacter,
            counterpartyName: 'Тестов платец',
            counterpartyIban: $counterpartyIban,
            remittanceInformation: 'Такса апартамент',
        );

        $this->entityManager->persist($import);
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return $transaction;
    }
}
