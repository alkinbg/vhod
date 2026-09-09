<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssemblyElectorateEntry;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Entity\User;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyPrincipalType;
use App\Enum\UnitRelationType;
use App\Service\AssemblyElectorateSnapshotService;
use App\Util\ExactDecimal;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AssemblyElectorateSnapshotServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private User $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $person = new Person('Мария', 'Управител', email: 'manager@example.com');
        $this->manager = new User($person, 'manager@example.com', 'hash');
        $this->manager->setRoles(['ROLE_MANAGER']);
        $this->entityManager->persist($person);
        $this->entityManager->persist($this->manager);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->isOpen()) {
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testSingleOwnerReceivesFullKnownUnitIdealParts(): void
    {
        $assembly = $this->persistAssembly();
        $unit = $this->persistUnit('Ап. 1', '8.2500');
        $owner = $this->persistPerson('Иван', 'Иванов');
        $relation = $this->persistOwnerRelation($owner, $unit, '2020-01-01');
        $this->entityManager->flush();

        $entries = $this->service()->createSnapshot($assembly, $this->at('2026-09-09T10:00:00Z'));
        $this->entityManager->flush();

        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame(AssemblyPrincipalType::PERSON, $entry->getPrincipalType());
        self::assertSame($owner, $entry->getPerson());
        self::assertSame($relation, $entry->getSourceRelation());
        self::assertSame('100.00000000', $entry->getOwnershipSharePercentSnapshot());
        self::assertSame('8.25000000', $entry->getUnitIdealPartsPercentSnapshot());
        self::assertSame('8.25000000', $entry->getRepresentedIdealPartsPercentSnapshot());
        self::assertTrue($entry->isQuorumEligible());
        self::assertNull($entry->getReviewReason());
    }

    public function testTwoFiftyPercentCoOwnersReceiveExactHalfWeightEach(): void
    {
        $assembly = $this->persistAssembly();
        $unit = $this->persistUnit('Ап. 2', '8.0000');
        $first = $this->persistPerson('Анна', 'Петрова');
        $second = $this->persistPerson('Борис', 'Петров');
        $this->persistOwnerRelation($first, $unit, '2020-01-01', '50.0000');
        $this->persistOwnerRelation($second, $unit, '2020-01-01', '50.0000');
        $this->entityManager->flush();

        $entries = $this->service()->createSnapshot($assembly, $this->at('2026-09-09T10:00:00Z'));

        self::assertCount(2, $entries);
        self::assertSame('4.00000000', $entries[0]->getRepresentedIdealPartsPercentSnapshot());
        self::assertSame('4.00000000', $entries[1]->getRepresentedIdealPartsPercentSnapshot());
        self::assertTrue($entries[0]->isQuorumEligible());
        self::assertTrue($entries[1]->isQuorumEligible());
    }

    public function testLegalEntityIdentityIsPreservedInSnapshot(): void
    {
        $assembly = $this->persistAssembly();
        $unit = $this->persistUnit('Магазин 1', '3.5000');
        $relation = UnitRelation::forLegalEntity(
            $unit,
            UnitRelationType::OWNER,
            new DateTimeImmutable('2020-01-01'),
            'Пример ООД',
            '123456789',
        );
        $this->entityManager->persist($relation);
        $this->entityManager->flush();

        $entry = $this->service()->createSnapshot($assembly, $this->at('2026-09-09T10:00:00Z'))[0];

        self::assertSame(AssemblyPrincipalType::LEGAL_ENTITY, $entry->getPrincipalType());
        self::assertNull($entry->getPerson());
        self::assertSame('Пример ООД', $entry->getPrincipalNameSnapshot());
        self::assertSame('123456789', $entry->getPrincipalIdentifierSnapshot());
        self::assertSame('3.50000000', $entry->getRepresentedIdealPartsPercentSnapshot());
    }

    public function testOnlyOwnerRelationsActiveOnReferenceDateAreSnapshotted(): void
    {
        $assembly = $this->persistAssembly();
        $unit = $this->persistUnit('Ап. 3', '6.0000');
        $currentOwner = $this->persistPerson('Текущ', 'Собственик');
        $expiredOwner = $this->persistPerson('Стар', 'Собственик');
        $futureOwner = $this->persistPerson('Бъдещ', 'Собственик');
        $current = $this->persistOwnerRelation($currentOwner, $unit, '2026-01-01');
        $expired = $this->persistOwnerRelation($expiredOwner, $unit, '2020-01-01');
        $expired->endAt(new DateTimeImmutable('2025-12-31'));
        $this->persistOwnerRelation($futureOwner, $unit, '2026-10-01');
        $this->entityManager->flush();

        $entries = $this->service()->createSnapshot($assembly, $this->at('2026-09-09T10:00:00Z'));

        self::assertCount(1, $entries);
        self::assertSame($current, $entries[0]->getSourceRelation());
        self::assertSame('Текущ Собственик', $entries[0]->getPrincipalNameSnapshot());
    }

    public function testMissingIdealPartsCreatesReviewEntryInsteadOfInventedWeight(): void
    {
        $assembly = $this->persistAssembly();
        $unit = $this->persistUnit('Ап. 4', null);
        $owner = $this->persistPerson('Николай', 'Георгиев');
        $this->persistOwnerRelation($owner, $unit, '2020-01-01');
        $this->entityManager->flush();

        $entry = $this->service()->createSnapshot($assembly, $this->at('2026-09-09T10:00:00Z'))[0];

        self::assertNull($entry->getUnitIdealPartsPercentSnapshot());
        self::assertNull($entry->getRepresentedIdealPartsPercentSnapshot());
        self::assertFalse($entry->isQuorumEligible());
        self::assertNotNull($entry->getReviewReason());
        self::assertNotSame('', trim((string) $entry->getReviewReason()));
    }

    public function testMissingCoOwnerSharesAreNeverGuessed(): void
    {
        $assembly = $this->persistAssembly();
        $unit = $this->persistUnit('Ап. 5', '10.0000');
        $first = $this->persistPerson('Първи', 'Съсобственик');
        $second = $this->persistPerson('Втори', 'Съсобственик');
        $this->persistOwnerRelation($first, $unit, '2020-01-01');
        $this->persistOwnerRelation($second, $unit, '2020-01-01');
        $this->entityManager->flush();

        $entries = $this->service()->createSnapshot($assembly, $this->at('2026-09-09T10:00:00Z'));

        self::assertCount(2, $entries);
        foreach ($entries as $entry) {
            self::assertNull($entry->getOwnershipSharePercentSnapshot());
            self::assertNull($entry->getRepresentedIdealPartsPercentSnapshot());
            self::assertFalse($entry->isQuorumEligible());
            self::assertNotNull($entry->getReviewReason());
        }
    }

    public function testContradictoryOwnershipShareTotalForcesReview(): void
    {
        $assembly = $this->persistAssembly();
        $unit = $this->persistUnit('Ап. 6', '10.0000');
        $first = $this->persistPerson('Първи', 'Собственик');
        $second = $this->persistPerson('Втори', 'Собственик');
        $this->persistOwnerRelation($first, $unit, '2020-01-01', '60.0000');
        $this->persistOwnerRelation($second, $unit, '2020-01-01', '60.0000');
        $this->entityManager->flush();

        $entries = $this->service()->createSnapshot($assembly, $this->at('2026-09-09T10:00:00Z'));

        self::assertCount(2, $entries);
        foreach ($entries as $entry) {
            self::assertNull($entry->getRepresentedIdealPartsPercentSnapshot());
            self::assertFalse($entry->isQuorumEligible());
            self::assertNotNull($entry->getReviewReason());
        }
    }

    public function testPersistedSnapshotDoesNotChangeWhenSourceRelationLaterEnds(): void
    {
        $assembly = $this->persistAssembly();
        $unit = $this->persistUnit('Ап. 7', '7.2500');
        $owner = $this->persistPerson('Исторически', 'Собственик');
        $relation = $this->persistOwnerRelation($owner, $unit, '2020-01-01');
        $this->entityManager->flush();

        $entry = $this->service()->createSnapshot($assembly, $this->at('2026-09-09T10:00:00Z'))[0];
        $this->entityManager->flush();
        $entryId = $entry->getId();
        self::assertNotNull($entryId);

        $relation->endAt(new DateTimeImmutable('2026-09-30'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(AssemblyElectorateEntry::class, $entryId);
        self::assertInstanceOf(AssemblyElectorateEntry::class, $reloaded);
        self::assertSame('100.00000000', $reloaded->getOwnershipSharePercentSnapshot());
        self::assertSame('7.25000000', $reloaded->getRepresentedIdealPartsPercentSnapshot());
        self::assertSame('Исторически Собственик', $reloaded->getPrincipalNameSnapshot());
    }

    public function testKnownTotalIsNotRescaledToOneHundredPercent(): void
    {
        $assembly = $this->persistAssembly();
        foreach ([['Ап. 8', '8.0000'], ['Ап. 9', '12.0000']] as [$designation, $idealParts]) {
            $unit = $this->persistUnit($designation, $idealParts);
            $owner = $this->persistPerson($designation, 'Собственик');
            $this->persistOwnerRelation($owner, $unit, '2020-01-01');
        }
        $this->entityManager->flush();

        $entries = $this->service()->createSnapshot($assembly, $this->at('2026-09-09T10:00:00Z'));
        $total = '0.00000000';
        foreach ($entries as $entry) {
            $weight = $entry->getRepresentedIdealPartsPercentSnapshot();
            self::assertNotNull($weight);
            $total = ExactDecimal::add($total, $weight);
        }

        self::assertSame('20.00000000', $total);
    }

    public function testSnapshotCanBeCreatedOnlyOnceAndOnlyWhileAssemblyIsDraft(): void
    {
        $assembly = $this->persistAssembly();
        $unit = $this->persistUnit('Ап. 10', '5.0000');
        $owner = $this->persistPerson('Един', 'Собственик');
        $this->persistOwnerRelation($owner, $unit, '2020-01-01');
        $this->entityManager->flush();

        $service = $this->service();
        $service->createSnapshot($assembly, $this->at('2026-09-09T10:00:00Z'));
        $this->entityManager->flush();

        try {
            $service->createSnapshot($assembly, $this->at('2026-09-09T10:05:00Z'));
            self::fail('A meeting electorate snapshot must not be created twice.');
        } catch (DomainException) {
            self::assertCount(1, $this->entityManager->getRepository(AssemblyElectorateEntry::class)->findBy(['assembly' => $assembly]));
        }

        $otherAssembly = $this->persistAssembly('Второ събрание');
        $otherAssembly->convene($this->manager, $this->at('2026-09-10T10:00:00Z'));
        $this->entityManager->flush();

        $this->expectException(DomainException::class);
        $service->createSnapshot($otherAssembly, $this->at('2026-09-10T10:01:00Z'));
    }

    private function service(): AssemblyElectorateSnapshotService
    {
        self::assertTrue(class_exists(AssemblyElectorateSnapshotService::class), 'AssemblyElectorateSnapshotService has not been implemented yet.');
        self::assertTrue(class_exists(AssemblyElectorateEntry::class), 'AssemblyElectorateEntry has not been implemented yet.');
        self::assertTrue(enum_exists(AssemblyPrincipalType::class), 'AssemblyPrincipalType has not been implemented yet.');

        return new AssemblyElectorateSnapshotService($this->entityManager);
    }

    private function persistAssembly(string $title = 'Общо събрание'): GeneralAssembly
    {
        $assembly = GeneralAssembly::draft(
            $title,
            $this->at('2026-09-20T15:00:00Z'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-09'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Мария Управител',
            $this->manager,
            $this->manager,
            $this->at('2026-09-09T08:00:00Z'),
        );
        $this->entityManager->persist($assembly);
        $this->entityManager->flush();

        return $assembly;
    }

    private function persistUnit(string $designation, ?string $idealParts): Unit
    {
        $unit = new Unit($designation, idealParts: $idealParts);
        $this->entityManager->persist($unit);

        return $unit;
    }

    private function persistPerson(string $firstName, string $lastName): Person
    {
        $person = new Person($firstName, $lastName);
        $this->entityManager->persist($person);

        return $person;
    }

    private function persistOwnerRelation(
        Person $person,
        Unit $unit,
        string $validFrom,
        ?string $ownershipShare = null,
    ): UnitRelation {
        $relation = new UnitRelation(
            $person,
            $unit,
            UnitRelationType::OWNER,
            new DateTimeImmutable($validFrom),
            $ownershipShare,
        );
        $this->entityManager->persist($relation);

        return $relation;
    }

    private function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }
}
