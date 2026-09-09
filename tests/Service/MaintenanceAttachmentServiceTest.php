<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\MaintenanceAttachment;
use App\Entity\MaintenanceSignal;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\MaintenanceSignalCategory;
use App\Enum\MaintenanceSignalPriority;
use App\Service\MaintenanceAttachmentService;
use App\Service\MaintenanceAttachmentStorage;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

final class MaintenanceAttachmentServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private string $directory;
    private MaintenanceAttachmentService $service;
    private User $resident;
    private User $otherResident;
    private MaintenanceSignal $signal;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $tool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $residentPerson = new Person('Иван', 'Иванов', email: 'resident@example.com');
        $otherPerson = new Person('Мария', 'Петрова', email: 'other@example.com');
        $this->resident = new User($residentPerson, 'resident@example.com', 'hash');
        $this->otherResident = new User($otherPerson, 'other@example.com', 'hash');
        $this->signal = MaintenanceSignal::open(
            $this->resident,
            MaintenanceSignalCategory::PLUMBING,
            MaintenanceSignalPriority::NORMAL,
            'Теч',
            'Теч в мазето.',
            'Мазе',
            new DateTimeImmutable('2026-09-08 18:00:00+00:00'),
        );

        foreach ([$residentPerson, $otherPerson, $this->resident, $this->otherResident, $this->signal] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $this->directory = sys_get_temp_dir().'/vhod-maintenance-service-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
        $this->service = new MaintenanceAttachmentService($entityManager, new MaintenanceAttachmentStorage($this->directory));
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->isOpen()) {
            $this->entityManager->close();
        }
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function testResidentCanAttachAllowedFileToOwnSignal(): void
    {
        $attachment = $this->service->add($this->resident, $this->signal, $this->pngUpload(), new DateTimeImmutable('2026-09-08 19:00:00+00:00'));

        self::assertNotNull($attachment->getId());
        self::assertSame($this->signal, $attachment->getSignal());
        self::assertSame($this->resident, $attachment->getUploadedBy());
        self::assertFileExists($this->directory.'/'.$attachment->getStorageName());
        self::assertCount(1, $this->entityManager->getRepository(MaintenanceAttachment::class)->findAll());
    }

    public function testResidentCannotAttachToAnotherResidentsSignal(): void
    {
        $this->expectException(DomainException::class);
        $this->service->add($this->otherResident, $this->signal, $this->pngUpload(), new DateTimeImmutable());
    }

    public function testStoredFileIsRemovedWhenPersistenceFails(): void
    {
        $transientSignal = MaintenanceSignal::open(
            $this->resident,
            MaintenanceSignalCategory::OTHER,
            MaintenanceSignalPriority::NORMAL,
            'Неперсистиран',
            'Тест за компенсиращо изтриване.',
            'Вход',
            new DateTimeImmutable(),
        );

        try {
            $this->service->add($this->resident, $transientSignal, $this->pngUpload(), new DateTimeImmutable());
            self::fail('Expected persistence to fail for transient signal association.');
        } catch (Throwable) {
            self::assertSame([], glob($this->directory.'/*') ?: []);
        }
    }

    private function pngUpload(): UploadedFile
    {
        $path = $this->directory.'/source-'.bin2hex(random_bytes(4)).'.png';
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        self::assertIsString($bytes);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'снимка.png', null, null, true);
    }
}
