<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AnimalRegistration;
use App\Entity\BookChangeDeclaration;
use App\Entity\HouseholdMember;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\UnitAbsence;
use App\Entity\UnitRelation;
use App\Entity\User;
use App\Enum\BookChangeType;
use App\Enum\BookDeclarationStatus;
use App\Enum\UnitRelationType;
use App\Service\BookChangeApplicationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BookChangeApplicationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private BookChangeApplicationService $service;
    private User $resident;
    private User $manager;
    private Unit $unit;

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

        self::assertTrue(class_exists(BookChangeApplicationService::class));
        $service = self::getContainer()->get(BookChangeApplicationService::class);
        self::assertInstanceOf(BookChangeApplicationService::class, $service);
        $this->service = $service;

        $residentPerson = new Person('Иван', 'Иванов', email: 'ivan@example.com');
        $this->resident = new User($residentPerson, 'ivan@example.com', 'hash');
        $this->manager = new User(new Person('Мария', 'Петрова'), 'manager@example.com', 'hash');
        $this->manager->setRoles(['ROLE_MANAGER']);
        $this->unit = new Unit('12');

        $relation = new UnitRelation(
            $residentPerson,
            $this->unit,
            UnitRelationType::OWNER,
            new DateTimeImmutable('2026-01-01'),
            '100.0000',
        );

        foreach ([$residentPerson, $this->resident, $this->manager->getPerson(), $this->manager, $this->unit, $relation] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function testAcceptingContactDeclarationUpdatesCanonicalPerson(): void
    {
        $declaration = $this->submit(BookChangeType::CONTACT_UPDATE, [
            'email' => 'new@example.com',
            'phone' => '+359888123456',
        ]);

        $this->service->accept($declaration, $this->manager, new DateTimeImmutable('2026-09-07 21:00:00'), 'Проверено.');

        self::assertSame(BookDeclarationStatus::ACCEPTED, $declaration->getStatus());
        self::assertSame('new@example.com', $this->resident->getPerson()->getEmail());
        self::assertSame('+359888123456', $this->resident->getPerson()->getPhone());
    }

    public function testAcceptingHouseholdDeclarationCreatesHistoricalMember(): void
    {
        $declaration = $this->submit(BookChangeType::HOUSEHOLD_MEMBER_ADD, [
            'firstName' => 'Мария',
            'lastName' => 'Иванова',
            'validFrom' => '2026-09-08',
        ]);

        $this->service->accept($declaration, $this->manager, new DateTimeImmutable('2026-09-07 21:00:00'));

        $members = $this->entityManager->getRepository(HouseholdMember::class)->findAll();
        self::assertCount(1, $members);
        self::assertSame('Мария Иванова', $members[0]->getPerson()->getDisplayName());
        self::assertSame($this->unit, $members[0]->getRelation()->getUnit());
    }

    public function testAcceptingAbsenceDeclarationCreatesAbsence(): void
    {
        $declaration = $this->submit(BookChangeType::ABSENCE, [
            'validFrom' => '2026-10-01',
            'validUntil' => '2026-10-15',
        ]);

        $this->service->accept($declaration, $this->manager, new DateTimeImmutable('2026-09-07 21:00:00'));

        $absences = $this->entityManager->getRepository(UnitAbsence::class)->findAll();
        self::assertCount(1, $absences);
        self::assertSame($this->resident->getPerson(), $absences[0]->getPerson());
        self::assertTrue($absences[0]->covers(new DateTimeImmutable('2026-10-10')));
    }

    public function testAcceptingAnimalDeclarationCreatesAnimalRecord(): void
    {
        $declaration = $this->submit(BookChangeType::ANIMAL, [
            'species' => 'куче',
            'count' => 1,
            'passport' => 'BG-123',
        ]);

        $this->service->accept($declaration, $this->manager, new DateTimeImmutable('2026-09-07 21:00:00'));

        $animals = $this->entityManager->getRepository(AnimalRegistration::class)->findAll();
        self::assertCount(1, $animals);
        self::assertSame('BG-123', $animals[0]->getVeterinaryPassportNumber());
    }

    /** @param array<string, bool|int|float|string|null> $payload */
    private function submit(BookChangeType $type, array $payload): BookChangeDeclaration
    {
        $declaration = BookChangeDeclaration::submit(
            $this->unit,
            $this->resident,
            $type,
            $payload,
            new DateTimeImmutable('2026-09-07 20:00:00'),
        );
        $this->entityManager->persist($declaration);
        $this->entityManager->flush();

        return $declaration;
    }
}
