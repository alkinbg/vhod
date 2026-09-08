<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BankAccount;
use App\Entity\BankStatementImport;
use App\Entity\BankTransaction;
use App\Enum\BankStatementFormat;
use App\Value\BankImportResult;
use App\Value\BankTransactionFingerprint;
use App\Value\NormalizedBankStatement;
use App\Value\NormalizedBankTransaction;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class BankStatementImportService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Camt053StatementParser $parser,
    ) {
    }

    public function importCamt053(
        BankAccount $bankAccount,
        string $xml,
        DateTimeImmutable $importedAt,
        ?string $sourceFilename = null,
    ): BankImportResult {
        if (null === $bankAccount->getId()) {
            throw new DomainException('Bank account must be persisted before statement import.');
        }
        if (!$bankAccount->isActive()) {
            throw new DomainException('Cannot import a statement for an inactive bank account.');
        }

        $contentHash = hash('sha256', $xml);
        $statement = $this->parser->parse($xml);
        if ($statement->accountIban !== $bankAccount->getIban()) {
            throw new DomainException('Statement account IBAN does not match the selected bank account.');
        }

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (
            $bankAccount,
            $statement,
            $contentHash,
            $importedAt,
            $sourceFilename,
        ): BankImportResult {
            $existingImport = $this->findImport($bankAccount, $contentHash);
            if (null !== $existingImport) {
                return new BankImportResult(
                    $existingImport,
                    0,
                    $existingImport->getTransactionCount(),
                    true,
                );
            }

            $statementImport = BankStatementImport::record(
                $bankAccount,
                BankStatementFormat::CAMT053,
                $contentHash,
                $importedAt,
                count($statement->transactions),
                $sourceFilename,
                $statement->statementReference,
                $statement->periodFrom,
                $statement->periodTo,
            );
            $entityManager->persist($statementImport);

            $createdTransactions = 0;
            $duplicateTransactions = 0;
            $seenFingerprints = [];

            foreach ($statement->transactions as $normalized) {
                $fingerprint = $this->fingerprint($bankAccount, $normalized);
                if (isset($seenFingerprints[$fingerprint]) || null !== $this->findTransaction($bankAccount, $fingerprint)) {
                    ++$duplicateTransactions;
                    continue;
                }
                $seenFingerprints[$fingerprint] = true;

                $entityManager->persist(BankTransaction::record(
                    $bankAccount,
                    $statementImport,
                    $fingerprint,
                    $normalized->amountCents,
                    $normalized->bookingDate,
                    $normalized->valueDate,
                    $normalized->bankTransactionId,
                    $normalized->entryReference,
                    $normalized->endToEndId,
                    $normalized->counterpartyName,
                    $normalized->counterpartyIban,
                    $normalized->remittanceInformation,
                ));
                ++$createdTransactions;
            }

            $entityManager->flush();

            return new BankImportResult(
                $statementImport,
                $createdTransactions,
                $duplicateTransactions,
                false,
            );
        });
    }

    private function findImport(BankAccount $bankAccount, string $contentHash): ?BankStatementImport
    {
        $import = $this->entityManager->getRepository(BankStatementImport::class)->findOneBy([
            'bankAccount' => $bankAccount,
            'contentHash' => $contentHash,
        ]);

        return $import instanceof BankStatementImport ? $import : null;
    }

    private function findTransaction(BankAccount $bankAccount, string $fingerprint): ?BankTransaction
    {
        $transaction = $this->entityManager->getRepository(BankTransaction::class)->findOneBy([
            'bankAccount' => $bankAccount,
            'fingerprint' => $fingerprint,
        ]);

        return $transaction instanceof BankTransaction ? $transaction : null;
    }

    private function fingerprint(BankAccount $bankAccount, NormalizedBankTransaction $transaction): string
    {
        return BankTransactionFingerprint::fromFields(
            $bankAccount->getIban(),
            $transaction->amountCents,
            $transaction->bookingDate,
            $transaction->valueDate,
            $transaction->bankTransactionId,
            $transaction->entryReference,
            $transaction->endToEndId,
            $transaction->counterpartyIban,
            $transaction->remittanceInformation,
        );
    }
}
