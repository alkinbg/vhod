<?php

declare(strict_types=1);

namespace App\Value;

use App\Entity\BankStatementImport;
use InvalidArgumentException;

final readonly class BankImportResult
{
    public function __construct(
        public BankStatementImport $statementImport,
        public int $createdTransactions,
        public int $duplicateTransactions,
        public bool $existingImport,
    ) {
        if ($createdTransactions < 0 || $duplicateTransactions < 0) {
            throw new InvalidArgumentException('Bank import transaction counts cannot be negative.');
        }
        if ($existingImport && 0 !== $createdTransactions) {
            throw new InvalidArgumentException('An existing bank import cannot create transactions.');
        }
    }
}
