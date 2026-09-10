<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Expense;
use App\Entity\ExpenseReversal;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class ExpenseReversalService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?AuditLogService $auditLog = null,
    ) {
    }

    public function reverse(Expense $expense, string $reason, DateTimeImmutable $reversedAt, ?User $actor = null): ExpenseReversal
    {
        if (null === $expense->getId()) {
            throw new DomainException('Only a persisted expense can be reversed.');
        }

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($expense, $reason, $reversedAt, $actor): ExpenseReversal {
            $entityManager->lock($expense, LockMode::PESSIMISTIC_WRITE);

            $existing = $entityManager->getRepository(ExpenseReversal::class)->findOneBy(['expense' => $expense]);
            if ($existing instanceof ExpenseReversal) {
                throw new DomainException('Expense has already been reversed.');
            }

            $reversal = ExpenseReversal::record($expense, $expense->getAmountCents(), $reason, $reversedAt);
            $entityManager->persist($reversal);
            $entityManager->flush();

            $this->auditLog?->record(
                $actor,
                'finance.expense.reversed',
                'Expense',
                $expense->getId(),
                $reversedAt,
                ['reversal_id' => $reversal->getId(), 'amount_cents' => $expense->getAmountCents()],
            );

            return $reversal;
        });
    }
}
