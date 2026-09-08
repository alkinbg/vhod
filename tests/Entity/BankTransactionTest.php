<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BankAccount;
use App\Entity\BankStatementImport;
use App\Entity\BankTransaction;
use App\Enum\BankStatementFormat;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BankTransactionTest extends TestCase
{
    public function testTransactionKeepsSignedImmutableNormalizedSnapshot(): void
    {
        $account = BankAccount::create('Основна сметка', 'BG80BNBG96611020345678');
        $import = BankStatementImport::record(
            $account,
            BankStatementFormat::CAMT053,
            str_repeat('a', 64),
            new DateTimeImmutable('2026-09-08 07:15:00 UTC'),
            1,
        );

        $transaction = BankTransaction::record(
            $account,
            $import,
            str_repeat('f', 64),
            12550,
            new DateTimeImmutable('2026-09-08'),
            new DateTimeImmutable('2026-09-09'),
            ' TX-1 ',
            ' ENTRY-1 ',
            ' E2E-1 ',
            ' Ivan Ivanov ',
            ' bg40 bnbg 9661 1000 0661 23 ',
            ' Такса ап. 12 ',
        );

        self::assertSame($account, $transaction->getBankAccount());
        self::assertSame($import, $transaction->getStatementImport());
        self::assertSame(str_repeat('f', 64), $transaction->getFingerprint());
        self::assertSame(12550, $transaction->getAmountCents());
        self::assertTrue($transaction->isIncoming());
        self::assertFalse($transaction->isOutgoing());
        self::assertSame('2026-09-08', $transaction->getBookingDate()->format('Y-m-d'));
        self::assertSame('2026-09-09', $transaction->getValueDate()?->format('Y-m-d'));
        self::assertSame('TX-1', $transaction->getBankTransactionId());
        self::assertSame('ENTRY-1', $transaction->getEntryReference());
        self::assertSame('E2E-1', $transaction->getEndToEndId());
        self::assertSame('Ivan Ivanov', $transaction->getCounterpartyName());
        self::assertSame('BG40BNBG96611000066123', $transaction->getCounterpartyIban());
        self::assertSame('Такса ап. 12', $transaction->getRemittanceInformation());
    }

    public function testNegativeAmountRepresentsOutgoingTransaction(): void
    {
        $account = BankAccount::create('Основна сметка', 'BG80BNBG96611020345678');
        $import = BankStatementImport::record(
            $account,
            BankStatementFormat::CAMT053,
            str_repeat('a', 64),
            new DateTimeImmutable('2026-09-08 07:15:00 UTC'),
            1,
        );
        $transaction = BankTransaction::record(
            $account,
            $import,
            str_repeat('e', 64),
            -3200,
            new DateTimeImmutable('2026-09-08'),
        );

        self::assertTrue($transaction->isOutgoing());
        self::assertFalse($transaction->isIncoming());
    }

    #[DataProvider('invalidAmounts')]
    public function testZeroAmountIsRejected(int $amountCents): void
    {
        $account = BankAccount::create('Основна сметка', 'BG80BNBG96611020345678');
        $import = BankStatementImport::record(
            $account,
            BankStatementFormat::CAMT053,
            str_repeat('a', 64),
            new DateTimeImmutable('2026-09-08 07:15:00 UTC'),
            1,
        );

        $this->expectException(InvalidArgumentException::class);

        BankTransaction::record(
            $account,
            $import,
            str_repeat('f', 64),
            $amountCents,
            new DateTimeImmutable('2026-09-08'),
        );
    }

    /** @return iterable<string, array{int}> */
    public static function invalidAmounts(): iterable
    {
        yield 'zero' => [0];
    }

    public function testStatementImportMustBelongToSameBankAccount(): void
    {
        $account = BankAccount::create('Основна сметка', 'BG80BNBG96611020345678');
        $other = BankAccount::create('Друга сметка', 'BG40BNBG96611000066123');
        $import = BankStatementImport::record(
            $other,
            BankStatementFormat::CAMT053,
            str_repeat('a', 64),
            new DateTimeImmutable('2026-09-08 07:15:00 UTC'),
            1,
        );

        $this->expectException(InvalidArgumentException::class);

        BankTransaction::record(
            $account,
            $import,
            str_repeat('f', 64),
            100,
            new DateTimeImmutable('2026-09-08'),
        );
    }
}
