<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Person;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PersonTest extends TestCase
{
    public function testBuildsDisplayNameWithoutRequiringMiddleName(): void
    {
        $person = new Person('Иван', 'Иванов');
        self::assertSame('Иван Иванов', $person->getDisplayName());
    }

    public function testRejectsInvalidEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Person('Иван', 'Иванов', email: 'not-an-email');
    }
}
