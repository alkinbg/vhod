<?php

declare(strict_types=1);

namespace App\Service;

use App\Value\Iban;
use App\Value\NormalizedBankStatement;
use App\Value\NormalizedBankTransaction;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use Throwable;

final class Camt053StatementParser
{
    public function parse(string $xml): NormalizedBankStatement
    {
        if ('' === trim($xml)) {
            throw new InvalidArgumentException('Invalid CAMT.053 XML.');
        }
        if (false !== stripos($xml, '<!DOCTYPE')) {
            throw new InvalidArgumentException('DOCTYPE is not allowed in CAMT.053 XML.');
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw new InvalidArgumentException('Invalid CAMT.053 XML.');
            }
            if (null !== $document->doctype) {
                throw new InvalidArgumentException('DOCTYPE is not allowed in CAMT.053 XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }

        $xpath = new DOMXPath($document);
        $statementNode = self::firstNode($xpath, '(//*[local-name()="Stmt"])[1]');
        if (!$statementNode instanceof DOMElement) {
            throw new InvalidArgumentException('CAMT.053 statement is required.');
        }

        $accountIban = self::firstText(
            $xpath,
            './*[local-name()="Acct"]/*[local-name()="Id"]/*[local-name()="IBAN"]',
            $statementNode,
        );
        if (null === $accountIban) {
            throw new InvalidArgumentException('CAMT.053 statement account IBAN is required.');
        }
        $accountIban = Iban::normalize($accountIban);

        $accountCurrency = self::firstText($xpath, './*[local-name()="Acct"]/*[local-name()="Ccy"]', $statementNode);
        if (null !== $accountCurrency && 'EUR' !== strtoupper($accountCurrency)) {
            throw new InvalidArgumentException('Only EUR CAMT.053 statements are supported.');
        }

        $statementReference = self::firstText($xpath, './*[local-name()="Id"]', $statementNode);
        $periodFrom = self::parseOptionalDate(self::firstText(
            $xpath,
            './*[local-name()="FrToDt"]/*[local-name()="FrDtTm" or local-name()="FrDt"]',
            $statementNode,
        ));
        $periodTo = self::parseOptionalDate(self::firstText(
            $xpath,
            './*[local-name()="FrToDt"]/*[local-name()="ToDtTm" or local-name()="ToDt"]',
            $statementNode,
        ));

        $entryNodes = $xpath->query('./*[local-name()="Ntry"]', $statementNode);
        if (false === $entryNodes) {
            throw new InvalidArgumentException('Invalid CAMT.053 statement entries.');
        }

        $transactions = [];
        foreach ($entryNodes as $entryNode) {
            if (!$entryNode instanceof DOMElement) {
                continue;
            }

            $transactions[] = $this->parseEntry($xpath, $entryNode);
        }

        return new NormalizedBankStatement(
            $accountIban,
            $transactions,
            $statementReference,
            $periodFrom,
            $periodTo,
        );
    }

    private function parseEntry(DOMXPath $xpath, DOMElement $entry): NormalizedBankTransaction
    {
        $amountNode = self::firstNode($xpath, './*[local-name()="Amt"]', $entry);
        if (!$amountNode instanceof DOMElement) {
            throw new InvalidArgumentException('CAMT.053 entry amount is required.');
        }

        $currency = strtoupper(trim($amountNode->getAttribute('Ccy')));
        if ('EUR' !== $currency) {
            throw new InvalidArgumentException('Only EUR CAMT.053 statements are supported.');
        }

        $amountCents = self::parseEurCents($amountNode->textContent);
        $creditDebit = strtoupper((string) self::firstText($xpath, './*[local-name()="CdtDbtInd"]', $entry));
        $amountCents = match ($creditDebit) {
            'CRDT' => $amountCents,
            'DBIT' => -$amountCents,
            default => throw new InvalidArgumentException('CAMT.053 credit/debit indicator is required.'),
        };

        $bookingDate = self::parseRequiredDate(
            self::firstText(
                $xpath,
                './*[local-name()="BookgDt"]/*[local-name()="Dt" or local-name()="DtTm"]',
                $entry,
            ),
            'CAMT.053 booking date is required.',
        );
        $valueDate = self::parseOptionalDate(self::firstText(
            $xpath,
            './*[local-name()="ValDt"]/*[local-name()="Dt" or local-name()="DtTm"]',
            $entry,
        ));
        $entryReference = self::firstText($xpath, './*[local-name()="AcctSvcrRef"]', $entry);

        $details = $xpath->query('./*[local-name()="NtryDtls"]/*[local-name()="TxDtls"]', $entry);
        if (false === $details) {
            throw new InvalidArgumentException('Invalid CAMT.053 transaction details.');
        }
        if ($details->length > 1) {
            throw new InvalidArgumentException('CAMT.053 entry with multiple transaction details is not supported.');
        }

        $transactionContext = 1 === $details->length ? $details->item(0) : $entry;
        if (!$transactionContext instanceof DOMNode) {
            $transactionContext = $entry;
        }

        $bankTransactionId = self::firstText(
            $xpath,
            './*[local-name()="Refs"]/*[local-name()="TxId"]',
            $transactionContext,
        );
        $endToEndId = self::firstText(
            $xpath,
            './*[local-name()="Refs"]/*[local-name()="EndToEndId"]',
            $transactionContext,
        );

        if ('CRDT' === $creditDebit) {
            $counterpartyName = self::firstText(
                $xpath,
                './*[local-name()="RltdPties"]/*[local-name()="Dbtr"]//*[local-name()="Nm"]',
                $transactionContext,
            );
            $counterpartyIban = self::firstText(
                $xpath,
                './*[local-name()="RltdPties"]/*[local-name()="DbtrAcct"]/*[local-name()="Id"]/*[local-name()="IBAN"]',
                $transactionContext,
            );
        } else {
            $counterpartyName = self::firstText(
                $xpath,
                './*[local-name()="RltdPties"]/*[local-name()="Cdtr"]//*[local-name()="Nm"]',
                $transactionContext,
            );
            $counterpartyIban = self::firstText(
                $xpath,
                './*[local-name()="RltdPties"]/*[local-name()="CdtrAcct"]/*[local-name()="Id"]/*[local-name()="IBAN"]',
                $transactionContext,
            );
        }

        $remittanceInformation = self::joinedText(
            $xpath,
            './*[local-name()="RmtInf"]/*[local-name()="Ustrd"]',
            $transactionContext,
        );

        return new NormalizedBankTransaction(
            $amountCents,
            'EUR',
            $bookingDate,
            $valueDate,
            $bankTransactionId,
            $entryReference,
            $endToEndId,
            $counterpartyName,
            $counterpartyIban,
            $remittanceInformation,
        );
    }

    private static function parseEurCents(string $amount): int
    {
        $amount = trim($amount);
        if (1 !== preg_match('/^([0-9]+)(?:\.([0-9]+))?$/D', $amount, $matches)) {
            throw new InvalidArgumentException('Invalid EUR amount.');
        }

        $whole = ltrim($matches[1], '0');
        $whole = '' === $whole ? '0' : $whole;
        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > 2 && 1 === preg_match('/[1-9]/', substr($fraction, 2))) {
            throw new InvalidArgumentException('Invalid EUR amount precision.');
        }

        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $maxWhole = intdiv(PHP_INT_MAX - 99, 100);
        if (strlen($whole) > strlen((string) $maxWhole)
            || (strlen($whole) === strlen((string) $maxWhole) && strcmp($whole, (string) $maxWhole) > 0)
        ) {
            throw new InvalidArgumentException('EUR amount is too large.');
        }

        return ((int) $whole * 100) + (int) $fraction;
    }

    private static function parseRequiredDate(?string $value, string $errorMessage): DateTimeImmutable
    {
        if (null === $value) {
            throw new InvalidArgumentException($errorMessage);
        }

        return self::parseDate($value);
    }

    private static function parseOptionalDate(?string $value): ?DateTimeImmutable
    {
        return null === $value ? null : self::parseDate($value);
    }

    private static function parseDate(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable(trim($value));
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Invalid CAMT.053 date.', previous: $exception);
        }
    }

    private static function firstNode(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?DOMNode
    {
        $nodes = $xpath->query($expression, $context);
        if (false === $nodes || 0 === $nodes->length) {
            return null;
        }

        return $nodes->item(0);
    }

    private static function firstText(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?string
    {
        $node = self::firstNode($xpath, $expression, $context);
        if (null === $node) {
            return null;
        }

        $value = trim($node->textContent);

        return '' === $value ? null : $value;
    }

    private static function joinedText(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?string
    {
        $nodes = $xpath->query($expression, $context);
        if (false === $nodes) {
            return null;
        }

        $parts = [];
        foreach ($nodes as $node) {
            $value = trim($node->textContent);
            if ('' !== $value) {
                $parts[] = $value;
            }
        }

        return [] === $parts ? null : implode(' ', $parts);
    }
}
