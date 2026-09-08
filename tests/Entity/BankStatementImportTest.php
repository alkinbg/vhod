<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BankAccount;
use App\Entity\BankStatementImport;
use App\Enum\BankStatementFormat;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BankStatementImportTest extends TestCase
{
    public function testImportKeepsImmutableAuditSnapshotInUtc(): void
    {
        $account = BankAccount::create('Основна сметка', 'BG80BNBG96611020345678');
        $import = BankStatementImport::record(
            $account,
            BankStatementFormat::CAMT053,
            str_repeat('a', 64),
            new DateTimeImmutable('2026-09-08 10:15:00', new DateTimeZone('Europe/Sofia')),
            2,
            ' statement.xml ',
            ' STMT-001 ',
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-09-08'),
        );

        self::assertSame($account, $import->getBankAccount());
        self::assertSame(BankStatementFormat::CAMT053, $import->getFormat());
        self::assertSame(str_repeat('a', 64), $import->getContentHash());
        self::assertSame('2026-09-08 07:15:00', $import->getImportedAt()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $import->getImportedAt()->getTimezone()->getName());
        self::assertSame(2, $import->getTransactionCount());
        self::assertSame('statement.xml', $import->getSourceFilename());
        self::assertSame('STMT-001', $import->getStatementReference());
        self::assertSame('2026-09-01', $import->getPeriodFrom()?->format('Y-m-d'));
        self::assertSame('2026-09-08', $import->getPeriodTo()?->format('Y-m-d'));
    }

    public function testInvalidContentHashIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BankStatementImport::record(
            BankAccount::create('Основна сметка', 'BG80BNBG96611020345678'),
            BankStatementFormat::CAMT053,
            'not-a-sha256',
            new DateTimeImmutable('2026-09-08 07:15:00 UTC'),
            0,
        );
    }

    public function testNegativeTransactionCountIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BankStatementImport::record(
            BankAccount::create('Основна сметка', 'BG80BNBG96611020345678'),
            BankStatementFormat::CAMT053,
            str_repeat('b', 64),
            new DateTimeImmutable('2026-09-08 07:15:00 UTC'),
            -1,
        );
    }

    public function testInvalidStatementPeriodIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BankStatementImport::record(
            BankAccount::create('Основна сметка', 'BG80BNBG96611020345678'),
            BankStatementFormat::CAMT053,
            str_repeat('c', 64),
            new DateTimeImmutable('2026-09-08 07:15:00 UTC'),
            0,
            periodFrom: new DateTimeImmutable('2026-09-09'),
            periodTo: new DateTimeImmutable('2026-09-08'),
        );
    }
}
