<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Person;
use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

final class UserCheckerTest extends TestCase
{
    public function testInactiveAccountCannotAuthenticate(): void
    {
        $user = new User(new Person('Иван', 'Иванов'), 'ivan@example.com', 'hash');
        $user->deactivate();
        $this->expectException(CustomUserMessageAccountStatusException::class);
        (new UserChecker())->checkPreAuth($user);
    }
}
