<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Payment;
use App\Entity\Unit;
use App\Enum\PaymentSource;
use App\Service\PaymentAllocator;
use App\Service\PaymentPostingService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PaymentPostingIdempotencyConflictTest extends KernelTestCase
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

    public function testSameExternalReferenceCannotSilentlyRepresentDifferentPayment(): void
    {
        $unit = new Unit('12');
        $this->entityManager->persist($unit);
        $this->entityManager->flush();

        $service = new PaymentPostingService(
            $this->entityManager,
            new PaymentAllocator($this->entityManager),
        );
        $service->post(
            $unit,
            850,
            PaymentSource::BANK_TRANSFER,
            new DateTimeImmutable('2026-09-08 07:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 07:05:00 UTC'),
            externalReference: 'bank-conflict-1',
        );

        try {
            $service->post(
                $unit,
                900,
                PaymentSource::BANK_TRANSFER,
                new DateTimeImmutable('2026-09-08 07:00:00 UTC'),
                new DateTimeImmutable('2026-09-08 07:06:00 UTC'),
                externalReference: 'bank-conflict-1',
            );
            self::fail('Expected conflicting idempotency key to be rejected.');
        } catch (DomainException $exception) {
            self::assertSame(
                'External payment reference is already used by a different payment.',
                $exception->getMessage(),
            );
        }

        self::assertCount(1, $this->entityManager->getRepository(Payment::class)->findAll());
    }
}
