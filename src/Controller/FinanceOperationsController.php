<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BankAccount;
use App\Entity\BankTransaction;
use App\Entity\Payment;
use App\Entity\PaymentReconciliation;
use App\Entity\PaymentReversal;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\PaymentSource;
use App\Service\BankReconciliationService;
use App\Service\BankStatementImportService;
use App\Service\MonthlyChargeGenerator;
use App\Service\PaymentPostingService;
use App\Service\PaymentReversalService;
use App\Value\EuroAmount;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FinanceOperationsController extends AbstractController
{
    private const SOFIA = 'Europe/Sofia';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MonthlyChargeGenerator $chargeGenerator,
        private readonly PaymentPostingService $paymentPostingService,
        private readonly PaymentReversalService $paymentReversalService,
        private readonly BankStatementImportService $bankStatementImportService,
        private readonly BankReconciliationService $bankReconciliationService,
    ) {
    }

    #[Route('/management/finance/operations', name: 'app_management_finance_operations', methods: ['GET'])]
    public function index(): Response
    {
        $this->requireFinanceWriter();

        return $this->render('management/finance/operations/index.html.twig', [
            'transactions' => $this->unreconciledIncomingTransactions(),
            'payments' => $this->recentEffectivePayments(),
            'bank_accounts' => $this->activeBankAccounts(),
        ]);
    }

    #[Route('/management/finance/operations/generate-charges', name: 'app_management_finance_generate_charges', methods: ['POST'])]
    public function generateCharges(Request $request): Response
    {
        $this->requireFinanceWriter();
        $this->requireCsrf('finance_generate_charges', $request);

        try {
            $month = self::parseMonth($request->request->getString('month'));
            $result = $this->chargeGenerator->generate($month, self::now());
            $this->addFlash('success', sprintf(
                'Начисленията са обработени: %d създадени, %d пропуснати.',
                $result->created,
                $result->skipped,
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_management_finance_operations');
    }

    #[Route('/management/finance/operations/payment/new', name: 'app_management_finance_payment_new', methods: ['GET', 'POST'])]
    public function paymentNew(Request $request): Response
    {
        $actor = $this->requireFinanceWriter();
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $this->requireCsrf('finance_payment_new', $request);

            try {
                $unit = $this->requireUnit($request->request->getInt('unit_id'));
                $this->paymentPostingService->post(
                    $unit,
                    EuroAmount::parse($request->request->getString('amount')),
                    PaymentSource::CASH,
                    self::parseDate($request->request->getString('received_at')),
                    self::now(),
                    $request->request->getString('reference'),
                    note: $request->request->getString('note'),
                    actor: $actor,
                );
                $this->addFlash('success', 'Плащането е записано и разпределено.');

                return $this->redirectToRoute('app_management_finance_operations');
            } catch (DomainException|InvalidArgumentException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('management/finance/operations/payment_new.html.twig', [
            'units' => $this->activeUnits(),
            'error' => $error,
        ], new Response(status: $status));
    }

    #[Route('/management/finance/operations/payment/{id}/reverse', name: 'app_management_finance_payment_reverse', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function paymentReverse(int $id, Request $request): Response
    {
        $actor = $this->requireFinanceWriter();
        $payment = $this->entityManager->find(Payment::class, $id);
        if (!$payment instanceof Payment) {
            throw $this->createNotFoundException('Payment not found.');
        }

        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->requireCsrf('finance_payment_reverse_'.$id, $request);

            try {
                $this->paymentReversalService->reverse(
                    $payment,
                    $request->request->getString('reason'),
                    self::now(),
                    $actor,
                );
                $this->addFlash('success', 'Плащането е сторнирано с отделен коригиращ запис.');

                return $this->redirectToRoute('app_management_finance_operations');
            } catch (DomainException|InvalidArgumentException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('management/finance/operations/payment_reverse.html.twig', [
            'payment' => $payment,
            'error' => $error,
        ], new Response(status: $status));
    }

    #[Route('/management/finance/operations/bank/import', name: 'app_management_finance_bank_import', methods: ['GET', 'POST'])]
    public function bankImport(Request $request): Response
    {
        $this->requireFinanceWriter();
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $this->requireCsrf('finance_bank_import', $request);

            try {
                $account = $this->requireBankAccount($request->request->getInt('bank_account_id'));
                $uploaded = $request->files->get('statement');
                if (!$uploaded instanceof UploadedFile || !$uploaded->isValid()) {
                    throw new InvalidArgumentException('Valid CAMT.053 XML file is required.');
                }

                $xml = file_get_contents($uploaded->getPathname());
                if (!is_string($xml) || '' === trim($xml)) {
                    throw new InvalidArgumentException('Statement file is empty or unreadable.');
                }

                $result = $this->bankStatementImportService->importCamt053(
                    $account,
                    $xml,
                    self::now(),
                    $uploaded->getClientOriginalName(),
                );
                $this->addFlash('success', sprintf(
                    'Банковият файл е обработен: %d нови транзакции, %d дублирани.',
                    $result->createdTransactions,
                    $result->duplicateTransactions,
                ));

                return $this->redirectToRoute('app_management_finance_operations');
            } catch (DomainException|InvalidArgumentException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('management/finance/operations/bank_import.html.twig', [
            'bank_accounts' => $this->activeBankAccounts(),
            'error' => $error,
        ], new Response(status: $status));
    }

    #[Route('/management/finance/operations/reconcile/{id}', name: 'app_management_finance_reconcile', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function reconcile(int $id, Request $request): Response
    {
        $this->requireFinanceWriter();
        $transaction = $this->entityManager->find(BankTransaction::class, $id);
        if (!$transaction instanceof BankTransaction) {
            throw $this->createNotFoundException('Bank transaction not found.');
        }

        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->requireCsrf('finance_reconcile_'.$id, $request);

            try {
                $paymentId = $request->request->getInt('payment_id');
                if ($paymentId > 0) {
                    $payment = $this->entityManager->find(Payment::class, $paymentId);
                    if (!$payment instanceof Payment) {
                        throw new InvalidArgumentException('Invalid payment.');
                    }
                    $this->bankReconciliationService->linkExistingPayment(
                        $transaction,
                        $payment,
                        self::now(),
                        $request->request->getString('note'),
                    );
                } else {
                    $this->bankReconciliationService->reconcileToUnit(
                        $transaction,
                        $this->requireUnit($request->request->getInt('unit_id')),
                        self::now(),
                        $request->request->getString('note'),
                    );
                }

                $this->addFlash('success', 'Банковата транзакция е осчетоводена.');

                return $this->redirectToRoute('app_management_finance_operations');
            } catch (DomainException|InvalidArgumentException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('management/finance/operations/reconcile.html.twig', [
            'transaction' => $transaction,
            'units' => $this->activeUnits(),
            'payments' => $this->recentEffectivePayments(),
            'error' => $error,
        ], new Response(status: $status));
    }

    private function requireFinanceWriter(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || (!$this->isGranted('ROLE_MANAGER') && !$this->isGranted('ROLE_CASHIER') && !$this->isGranted('ROLE_ADMIN'))) {
            throw $this->createAccessDeniedException('Finance write access is required.');
        }

        return $user;
    }

    private function requireCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function requireUnit(int $id): Unit
    {
        $unit = $this->entityManager->find(Unit::class, $id);
        if (!$unit instanceof Unit || !$unit->isActive()) {
            throw new InvalidArgumentException('Invalid unit.');
        }

        return $unit;
    }

    private function requireBankAccount(int $id): BankAccount
    {
        $account = $this->entityManager->find(BankAccount::class, $id);
        if (!$account instanceof BankAccount || !$account->isActive()) {
            throw new InvalidArgumentException('Invalid bank account.');
        }

        return $account;
    }

    /** @return list<Unit> */
    private function activeUnits(): array
    {
        return $this->entityManager->getRepository(Unit::class)->findBy(['active' => true], ['designation' => 'ASC']);
    }

    /** @return list<BankAccount> */
    private function activeBankAccounts(): array
    {
        return $this->entityManager->getRepository(BankAccount::class)->findBy(['active' => true], ['name' => 'ASC']);
    }

    /** @return list<BankTransaction> */
    private function unreconciledIncomingTransactions(): array
    {
        $result = [];
        foreach ($this->entityManager->getRepository(BankTransaction::class)->findBy([], ['bookingDate' => 'DESC', 'id' => 'DESC'], 100) as $transaction) {
            if (!$transaction->isIncoming()) {
                continue;
            }
            if (null !== $this->entityManager->getRepository(PaymentReconciliation::class)->findOneBy(['bankTransaction' => $transaction])) {
                continue;
            }
            $result[] = $transaction;
        }

        return $result;
    }

    /** @return list<Payment> */
    private function recentEffectivePayments(): array
    {
        $result = [];
        foreach ($this->entityManager->getRepository(Payment::class)->findBy([], ['receivedAt' => 'DESC', 'id' => 'DESC'], 100) as $payment) {
            if (null !== $this->entityManager->getRepository(PaymentReversal::class)->findOneBy(['payment' => $payment])) {
                continue;
            }
            $result[] = $payment;
        }

        return $result;
    }

    private static function parseMonth(string $input): DateTimeImmutable
    {
        if (1 !== preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $input)) {
            throw new InvalidArgumentException('Invalid billing month.');
        }

        return new DateTimeImmutable($input.'-01 00:00:00', new DateTimeZone(self::SOFIA));
    }

    private static function parseDate(string $input): DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/D', $input)) {
            throw new InvalidArgumentException('Invalid date.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input, new DateTimeZone(self::SOFIA));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $input) {
            throw new InvalidArgumentException('Invalid date.');
        }

        return $date;
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::SOFIA));
    }
}
