<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Charge;
use App\Entity\FeePolicy;
use App\Entity\Fund;
use App\Entity\Unit;
use App\Enum\FeeCategory;
use App\Enum\FeeDistribution;
use App\Enum\FundType;
use App\Service\MonthlyChargeGenerator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MonthlyChargeGeneratorIdempotencyTest extends KernelTestCase
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

    public function testGeneratingSameMonthTwiceSkipsExistingCharges(): void
    {
        $fund = new Fund('operating', 'Текуща поддръжка', FundType::OPERATING);
        $policy = FeePolicy::create(
            'maintenance',
            'Месечна поддръжка',
            $fund,
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_UNIT,
            850,
            new DateTimeImmutable('2026-09-01'),
            'ОС 01/2026, т. 4',
        );
        $unit12 = new Unit('12');
        $unit13 = new Unit('13');

        foreach ([$fund, $policy, $unit12, $unit13] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $generator = new MonthlyChargeGenerator($this->entityManager);
        $first = $generator->generate(
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-09-08 05:40:00 UTC'),
        );
        $second = $generator->generate(
            new DateTimeImmutable('2026-09-18'),
            new DateTimeImmutable('2026-09-08 05:41:00 UTC'),
        );

        self::assertSame(2, $first->created);
        self::assertSame(1700, $first->totalAmountCents);
        self::assertSame(0, $second->created);
        self::assertSame(2, $second->skipped);
        self::assertSame(0, $second->totalAmountCents);
        self::assertCount(2, $this->entityManager->getRepository(Charge::class)->findAll());
    }

    public function testOverlappingEffectiveVersionsOfSamePolicyCodeAbortBeforePosting(): void
    {
        $fund = new Fund('operating', 'Текуща поддръжка', FundType::OPERATING);
        $older = FeePolicy::create(
            'maintenance',
            'Стара месечна поддръжка',
            $fund,
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_UNIT,
            800,
            new DateTimeImmutable('2026-08-01'),
            'ОС 05/2026, т. 3',
        );
        $newer = FeePolicy::create(
            'maintenance',
            'Нова месечна поддръжка',
            $fund,
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_UNIT,
            850,
            new DateTimeImmutable('2026-09-01'),
            'ОС 06/2026, т. 2',
        );
        $unit = new Unit('12');

        foreach ([$fund, $older, $newer, $unit] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        try {
            (new MonthlyChargeGenerator($this->entityManager))->generate(
                new DateTimeImmutable('2026-09-01'),
                new DateTimeImmutable('2026-09-08 05:42:00 UTC'),
            );
            self::fail('Expected overlapping policy versions to abort generation.');
        } catch (DomainException $exception) {
            self::assertSame(
                'Multiple fee policy versions with code "maintenance" are effective for 2026-09.',
                $exception->getMessage(),
            );
        }

        self::assertCount(0, $this->entityManager->getRepository(Charge::class)->findAll());
    }
}
