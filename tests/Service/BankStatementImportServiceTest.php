<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\BankAccount;
use App\Entity\BankStatementImport;
use App\Entity\BankTransaction;
use App\Service\BankStatementImportService;
use App\Service\Camt053StatementParser;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BankStatementImportServiceTest extends KernelTestCase
{
    private const ACCOUNT_IBAN = 'BG35TEST00000000000000';
    private const OTHER_ACCOUNT_IBAN = 'BG22DEMO00000000000002';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $tool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function testSameFileIsIdempotentByContentHash(): void
    {
        $account = $this->persistAccount(self::ACCOUNT_IBAN);
        $xml = $this->fixture('camt053-basic.xml');
        $service = $this->service();
        $now = new DateTimeImmutable('2026-09-08 08:00:00 Europe/Sofia');

        $first = $service->importCamt053($account, $xml, $now, 'statement.xml');
        $second = $service->importCamt053($account, $xml, $now, 'statement-again.xml');

        self::assertFalse($first->existingImport);
        self::assertSame(2, $first->createdTransactions);
        self::assertSame(0, $first->duplicateTransactions);
        self::assertTrue($second->existingImport);
        self::assertSame(0, $second->createdTransactions);
        self::assertSame(2, $second->duplicateTransactions);
        self::assertSame($first->statementImport->getId(), $second->statementImport->getId());
        self::assertSame('statement.xml', $second->statementImport->getSourceFilename());
        self::assertCount(1, $this->entityManager->getRepository(BankStatementImport::class)->findAll());
        self::assertCount(2, $this->entityManager->getRepository(BankTransaction::class)->findAll());
    }

    public function testAccountIbanMismatchWritesNothing(): void
    {
        $account = $this->persistAccount(self::OTHER_ACCOUNT_IBAN);

        try {
            $this->service()->importCamt053(
                $account,
                $this->fixture('camt053-basic.xml'),
                new DateTimeImmutable('2026-09-08 08:00:00 UTC'),
            );
            self::fail('Expected account mismatch to be rejected.');
        } catch (DomainException $exception) {
            self::assertSame('Statement account IBAN does not match the selected bank account.', $exception->getMessage());
        }

        self::assertCount(0, $this->entityManager->getRepository(BankStatementImport::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(BankTransaction::class)->findAll());
    }

    public function testInactiveAccountWritesNothing(): void
    {
        $account = $this->persistAccount(self::ACCOUNT_IBAN);
        $account->deactivate();
        $this->entityManager->flush();

        try {
            $this->service()->importCamt053(
                $account,
                $this->fixture('camt053-basic.xml'),
                new DateTimeImmutable('2026-09-08 08:00:00 UTC'),
            );
            self::fail('Expected inactive account to be rejected.');
        } catch (DomainException $exception) {
            self::assertSame('Cannot import a statement for an inactive bank account.', $exception->getMessage());
        }

        self::assertCount(0, $this->entityManager->getRepository(BankStatementImport::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(BankTransaction::class)->findAll());
    }

    public function testOverlappingStatementsIgnoreChangedOptionalReferencesAndKeepOnlyNewTransactions(): void
    {
        $account = $this->persistAccount(self::ACCOUNT_IBAN);
        $service = $this->service();

        $first = $service->importCamt053(
            $account,
            $this->fixture('camt053-basic.xml'),
            new DateTimeImmutable('2026-09-08 08:00:00 UTC'),
            'first.xml',
        );
        $overlap = $service->importCamt053(
            $account,
            $this->fixture('camt053-overlap.xml'),
            new DateTimeImmutable('2026-09-09 08:00:00 UTC'),
            'overlap.xml',
        );

        self::assertSame(2, $first->createdTransactions);
        self::assertFalse($overlap->existingImport);
        self::assertSame(1, $overlap->createdTransactions);
        self::assertSame(1, $overlap->duplicateTransactions);
        self::assertSame(2, $overlap->statementImport->getTransactionCount());
        self::assertSame('STMT-2026-09-09', $overlap->statementImport->getStatementReference());
        self::assertCount(2, $this->entityManager->getRepository(BankStatementImport::class)->findAll());
        self::assertCount(3, $this->entityManager->getRepository(BankTransaction::class)->findAll());
    }

    public function testTransientBankAccountIsRejected(): void
    {
        $account = BankAccount::create('Основна сметка', self::ACCOUNT_IBAN);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Bank account must be persisted before statement import.');

        $this->service()->importCamt053(
            $account,
            $this->fixture('camt053-basic.xml'),
            new DateTimeImmutable('2026-09-08 08:00:00 UTC'),
        );
    }

    private function service(): BankStatementImportService
    {
        return new BankStatementImportService($this->entityManager, new Camt053StatementParser());
    }

    private function persistAccount(string $iban): BankAccount
    {
        $account = BankAccount::create('Основна сметка', $iban);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function fixture(string $filename): string
    {
        $contents = file_get_contents(__DIR__.'/../Fixtures/bank/'.$filename);
        self::assertIsString($contents);

        return $contents;
    }
}
