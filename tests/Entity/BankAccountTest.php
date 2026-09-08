<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BankAccount;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BankAccountTest extends TestCase
{
    public function testAccountNormalizesItsLedgerIdentity(): void
    {
        $account = BankAccount::create('  Основна сметка  ', ' bg80 bnbg 9661 1020 3456 78 ');

        self::assertSame('Основна сметка', $account->getName());
        self::assertSame('BG80BNBG96611020345678', $account->getIban());
        self::assertSame('EUR', $account->getCurrency());
        self::assertTrue($account->isActive());

        $account->deactivate();
        self::assertFalse($account->isActive());
    }

    public function testBlankNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BankAccount::create(' ', 'BG80BNBG96611020345678');
    }

    public function testInvalidIbanIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BankAccount::create('Основна сметка', 'BG81BNBG96611020345678');
    }
}
