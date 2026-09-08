<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Camt053StatementParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class Camt053StatementParserTest extends TestCase
{
    public function testParserNormalizesCreditAndDebitEntries(): void
    {
        $statement = (new Camt053StatementParser())->parse(self::fixture());

        self::assertSame('BG80BNBG96611020345678', $statement->accountIban);
        self::assertSame('STMT-2026-09-08', $statement->statementReference);
        self::assertSame('2026-09-01', $statement->periodFrom?->format('Y-m-d'));
        self::assertSame('2026-09-08', $statement->periodTo?->format('Y-m-d'));
        self::assertCount(2, $statement->transactions);

        $credit = $statement->transactions[0];
        self::assertSame(12550, $credit->amountCents);
        self::assertSame('EUR', $credit->currency);
        self::assertSame('2026-09-08', $credit->bookingDate->format('Y-m-d'));
        self::assertSame('2026-09-08', $credit->valueDate?->format('Y-m-d'));
        self::assertSame('TX-CREDIT-1', $credit->bankTransactionId);
        self::assertSame('ASR-CREDIT-1', $credit->entryReference);
        self::assertSame('E2E-CREDIT-1', $credit->endToEndId);
        self::assertSame('Иван Иванов', $credit->counterpartyName);
        self::assertSame('BG40BNBG96611000066123', $credit->counterpartyIban);
        self::assertSame('Такса ап. 12', $credit->remittanceInformation);

        $debit = $statement->transactions[1];
        self::assertSame(-3200, $debit->amountCents);
        self::assertSame('Сервиз ООД', $debit->counterpartyName);
        self::assertSame('Ремонт входна врата', $debit->remittanceInformation);
    }

    public function testMalformedXmlIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid CAMT.053 XML.');

        (new Camt053StatementParser())->parse('<Document><broken></Document>');
    }

    public function testDoctypeIsRejectedBeforeXmlParsing(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE Document [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
<Document><BkToCstmrStmt><Stmt><Acct><Id><IBAN>&xxe;</IBAN></Id></Acct></Stmt></BkToCstmrStmt></Document>
XML;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DOCTYPE is not allowed in CAMT.053 XML.');

        (new Camt053StatementParser())->parse($xml);
    }

    public function testNonEurStatementIsRejected(): void
    {
        $xml = str_replace(['<Ccy>EUR</Ccy>', 'Ccy="EUR"'], ['<Ccy>USD</Ccy>', 'Ccy="USD"'], self::fixture());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only EUR CAMT.053 statements are supported.');

        (new Camt053StatementParser())->parse($xml);
    }

    public function testMissingStatementAccountIbanIsRejected(): void
    {
        $xml = str_replace('<IBAN>BG80BNBG96611020345678</IBAN>', '', self::fixture());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CAMT.053 statement account IBAN is required.');

        (new Camt053StatementParser())->parse($xml);
    }

    public function testAmountWithUnsupportedPrecisionIsRejected(): void
    {
        $xml = str_replace('>125.50</Amt>', '>125.501</Amt>', self::fixture());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid EUR amount precision.');

        (new Camt053StatementParser())->parse($xml);
    }

    private static function fixture(): string
    {
        $xml = file_get_contents(__DIR__.'/../Fixtures/bank/camt053-basic.xml');
        self::assertIsString($xml);

        return $xml;
    }
}
