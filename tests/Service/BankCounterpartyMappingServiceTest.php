<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\BankCounterpartyMapping;
use App\Entity\Unit;
use App\Service\BankCounterpartyMappingService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BankCounterpartyMappingServiceTest extends KernelTestCase
{
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

    public function testAssignCreatesOneActiveMappingAndIsIdempotentForSameUnit(): void
    {
        $unit = $this->persistUnit('12');
        $service = new BankCounterpartyMappingService($this->entityManager);
        $createdAt = new DateTimeImmutable('2026-09-08 08:00:00 Europe/Sofia');

        $first = $service->assign(' bg40 bnbg 9661 1000 0661 23 ', $unit, $createdAt);
        $second = $service->assign('BG40BNBG96611000066123', $unit, $createdAt);

        self::assertNotNull($first->getId());
        self::assertSame($first->getId(), $second->getId());
        self::assertTrue($second->isActive());
        self::assertCount(1, $this->entityManager->getRepository(BankCounterpartyMapping::class)->findAll());
    }

    public function testActiveMappingCannotSilentlyMoveToAnotherUnit(): void
    {
        $unit12 = $this->persistUnit('12');
        $unit13 = $this->persistUnit('13');
        $service = new BankCounterpartyMappingService($this->entityManager);
        $service->assign(
            'BG40BNBG96611000066123',
            $unit12,
            new DateTimeImmutable('2026-09-08 05:00:00 UTC'),
        );

        try {
            $service->assign(
                'BG40BNBG96611000066123',
                $unit13,
                new DateTimeImmutable('2026-09-08 05:05:00 UTC'),
            );
            self::fail('Expected conflicting payer mapping to be rejected.');
        } catch (DomainException $exception) {
            self::assertSame('Counterparty IBAN is already mapped to another unit.', $exception->getMessage());
        }

        self::assertCount(1, $this->entityManager->getRepository(BankCounterpartyMapping::class)->findAll());
    }

    public function testDeactivatedMappingPreservesHistoryAndAllowsExplicitReassignment(): void
    {
        $unit12 = $this->persistUnit('12');
        $unit13 = $this->persistUnit('13');
        $service = new BankCounterpartyMappingService($this->entityManager);
        $old = $service->assign(
            'BG40BNBG96611000066123',
            $unit12,
            new DateTimeImmutable('2026-09-08 05:00:00 UTC'),
        );
        $old->deactivate();
        $this->entityManager->flush();

        $new = $service->assign(
            'BG40BNBG96611000066123',
            $unit13,
            new DateTimeImmutable('2026-09-09 05:00:00 UTC'),
        );

        self::assertFalse($old->isActive());
        self::assertTrue($new->isActive());
        self::assertSame($unit13->getId(), $new->getUnit()->getId());
        self::assertCount(2, $this->entityManager->getRepository(BankCounterpartyMapping::class)->findAll());
    }

    public function testInactiveUnitCannotReceiveNewPayerMapping(): void
    {
        $unit = $this->persistUnit('12');
        $unit->deactivate();
        $this->entityManager->flush();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Counterparty mapping requires an active unit.');

        (new BankCounterpartyMappingService($this->entityManager))->assign(
            'BG40BNBG96611000066123',
            $unit,
            new DateTimeImmutable('2026-09-08 05:00:00 UTC'),
        );
    }

    public function testTransientUnitCannotReceivePayerMapping(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Unit must be persisted before assigning a counterparty mapping.');

        (new BankCounterpartyMappingService($this->entityManager))->assign(
            'BG40BNBG96611000066123',
            new Unit('12'),
            new DateTimeImmutable('2026-09-08 05:00:00 UTC'),
        );
    }

    private function persistUnit(string $designation): Unit
    {
        $unit = new Unit($designation);
        $this->entityManager->persist($unit);
        $this->entityManager->flush();

        return $unit;
    }
}
