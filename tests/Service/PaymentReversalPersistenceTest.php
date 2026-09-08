<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Payment;
use App\Entity\Unit;
use App\Enum\PaymentSource;
use App\Service\PaymentReversalService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PaymentReversalPersistenceTest extends KernelTestCase
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

    public function testUnpersistedPaymentCannotBeReversed(): void
    {
        $payment = Payment::post(
            new Unit('12'),
            850,
            PaymentSource::CASH,
            new DateTimeImmutable('2026-09-08 07:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 07:05:00 UTC'),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only a persisted payment can be reversed.');

        (new PaymentReversalService($this->entityManager))->reverse(
            $payment,
            'Корекция.',
            new DateTimeImmutable('2026-09-08 08:00:00 UTC'),
        );
    }
}
