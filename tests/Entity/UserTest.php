<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Person;
use App\Entity\User;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testEveryAccountAlwaysHasResidentRole(): void
    {
        $user = new User(new Person('Иван', 'Иванов'), 'IVAN@example.com', 'hash');
        $user->setRoles(['ROLE_MANAGER', 'ROLE_MANAGER']);
        self::assertSame('ivan@example.com', $user->getUserIdentifier());
        self::assertSame(['ROLE_MANAGER', 'ROLE_USER'], $user->getRoles());
    }

    public function testRejectsUnknownSecurityRole(): void
    {
        $user = new User(new Person('Иван', 'Иванов'), 'ivan@example.com', 'hash');
        $this->expectException(InvalidArgumentException::class);
        $user->setRoles(['ROLE_SUPER_DUPER']);
    }
}
