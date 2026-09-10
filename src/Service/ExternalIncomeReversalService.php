<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ExternalIncome;
use App\Entity\ExternalIncomeReversal;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class ExternalIncomeReversalService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?AuditLogService $auditLog = null,
    ) {
    }

    public function reverse(ExternalIncome $income, string $reason, DateTimeImmutable $reversedAt, ?User $actor = null): ExternalIncomeReversal
    {
        if (null === $income->getId()) {
            throw new DomainException('Only a persisted external income can be reversed.');
        }

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($income, $reason, $reversedAt, $actor): ExternalIncomeReversal {
            $entityManager->lock($income, LockMode::PESSIMISTIC_WRITE);

            $existing = $entityManager->getRepository(ExternalIncomeReversal::class)->findOneBy(['income' => $income]);
            if ($existing instanceof ExternalIncomeReversal) {
                throw new DomainException('External income has already been reversed.');
            }

            $reversal = ExternalIncomeReversal::record($income, $income->getAmountCents(), $reason, $reversedAt);
            $entityManager->persist($reversal);
            $entityManager->flush();

            $this->auditLog?->record(
                $actor,
                'finance.external_income.reversed',
                'ExternalIncome',
                $income->getId(),
                $reversedAt,
                ['reversal_id' => $reversal->getId(), 'amount_cents' => $income->getAmountCents()],
            );

            return $reversal;
        });
    }
}
