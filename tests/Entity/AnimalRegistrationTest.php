<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AnimalRegistration;
use App\Entity\Unit;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AnimalRegistrationTest extends TestCase
{
    public function testAnimalRecordKeepsSpeciesCountAndPassport(): void
    {
        self::assertTrue(class_exists(AnimalRegistration::class));

        $registration = new AnimalRegistration(new Unit('12'), 'куче', 1, 'BG-123456');

        self::assertSame('куче', $registration->getSpecies());
        self::assertSame(1, $registration->getCount());
        self::assertSame('BG-123456', $registration->getVeterinaryPassportNumber());
    }

    public function testAnimalCountMustBePositive(): void
    {
        self::assertTrue(class_exists(AnimalRegistration::class));

        $this->expectException(InvalidArgumentException::class);
        new AnimalRegistration(new Unit('12'), 'котка', 0);
    }
}
