<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BankAccount;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BankAccountTest extends TestCase
{
    private const ACCOUNT_IBAN = 'BG35TEST00000000000000';

    public function testAccountNormalizesItsLedgerIdentity(): void
    {
        $account = BankAccount::create('  Основна сметка  ', ' bg35 test 0000 0000 0000 00 ');

        self::assertSame('Основна сметка', $account->getName());
        self::assertSame(self::ACCOUNT_IBAN, $account->getIban());
        self::assertSame('EUR', $account->getCurrency());
        self::assertTrue($account->isActive());

        $account->deactivate();
        self::assertFalse($account->isActive());
    }

    public function testBlankNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BankAccount::create(' ', self::ACCOUNT_IBAN);
    }

    public function testInvalidIbanIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BankAccount::create('Основна сметка', 'BG36TEST00000000000000');
    }
}
