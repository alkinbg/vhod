<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BankStatementFormat;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'bank_statement_import')]
#[ORM\UniqueConstraint(name: 'uniq_bank_statement_import_account_hash', columns: ['bank_account_id', 'content_hash'])]
#[ORM\Index(name: 'idx_bank_statement_import_account_date', columns: ['bank_account_id', 'imported_at'])]
class BankStatementImport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private BankAccount $bankAccount;

    #[ORM\Column(enumType: BankStatementFormat::class)]
    private BankStatementFormat $format;

    #[ORM\Column(length: 64)]
    private string $contentHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $importedAt;

    #[ORM\Column]
    private int $transactionCount;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceFilename;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $statementReference;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $periodFrom;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $periodTo;

    private function __construct(
        BankAccount $bankAccount,
        BankStatementFormat $format,
        string $contentHash,
        DateTimeImmutable $importedAt,
        int $transactionCount,
        ?string $sourceFilename,
        ?string $statementReference,
        ?DateTimeImmutable $periodFrom,
        ?DateTimeImmutable $periodTo,
    ) {
        $contentHash = strtolower(trim($contentHash));
        if (1 !== preg_match('/^[a-f0-9]{64}$/D', $contentHash)) {
            throw new InvalidArgumentException('Statement content hash must be a SHA-256 hex digest.');
        }
        if ($transactionCount < 0) {
            throw new InvalidArgumentException('Statement transaction count cannot be negative.');
        }

        $periodFrom = self::normalizeDate($periodFrom);
        $periodTo = self::normalizeDate($periodTo);
        if (null !== $periodFrom && null !== $periodTo && $periodFrom > $periodTo) {
            throw new InvalidArgumentException('Statement period start cannot be after its end.');
        }

        $this->bankAccount = $bankAccount;
        $this->format = $format;
        $this->contentHash = $contentHash;
        $this->importedAt = $importedAt->setTimezone(new DateTimeZone('UTC'));
        $this->transactionCount = $transactionCount;
        $this->sourceFilename = self::nullableTrim($sourceFilename);
        $this->statementReference = self::nullableTrim($statementReference);
        $this->periodFrom = $periodFrom;
        $this->periodTo = $periodTo;
    }

    public static function record(
        BankAccount $bankAccount,
        BankStatementFormat $format,
        string $contentHash,
        DateTimeImmutable $importedAt,
        int $transactionCount,
        ?string $sourceFilename = null,
        ?string $statementReference = null,
        ?DateTimeImmutable $periodFrom = null,
        ?DateTimeImmutable $periodTo = null,
    ): self {
        return new self(
            $bankAccount,
            $format,
            $contentHash,
            $importedAt,
            $transactionCount,
            $sourceFilename,
            $statementReference,
            $periodFrom,
            $periodTo,
        );
    }

    public function getId(): ?int { return $this->id; }
    public function getBankAccount(): BankAccount { return $this->bankAccount; }
    public function getFormat(): BankStatementFormat { return $this->format; }
    public function getContentHash(): string { return $this->contentHash; }
    public function getImportedAt(): DateTimeImmutable { return $this->importedAt; }
    public function getTransactionCount(): int { return $this->transactionCount; }
    public function getSourceFilename(): ?string { return $this->sourceFilename; }
    public function getStatementReference(): ?string { return $this->statementReference; }
    public function getPeriodFrom(): ?DateTimeImmutable { return $this->periodFrom; }
    public function getPeriodTo(): ?DateTimeImmutable { return $this->periodTo; }

    private static function normalizeDate(?DateTimeImmutable $date): ?DateTimeImmutable
    {
        if (null === $date) {
            return null;
        }

        return new DateTimeImmutable($date->format('Y-m-d').' 00:00:00', new DateTimeZone('UTC'));
    }

    private static function nullableTrim(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
