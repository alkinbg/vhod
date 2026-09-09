<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MaintenanceAttachment;
use App\Entity\MaintenanceSignal;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\MaintenanceSignalCategory;
use App\Enum\MaintenanceSignalPriority;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MaintenanceControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $resident;
    private User $otherResident;
    private int $otherSignalId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $entityManager = $this->entityManager();
        $tool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->resident = $this->persistUser($entityManager, 'resident@example.com', 'Иван', 'Иванов');
        $this->otherResident = $this->persistUser($entityManager, 'other@example.com', 'Мария', 'Петрова');
        $otherSignal = MaintenanceSignal::open(
            $this->otherResident,
            MaintenanceSignalCategory::COMMON_AREA,
            MaintenanceSignalPriority::NORMAL,
            'Чужд сигнал',
            'Сигнал от друг живущ.',
            'Етаж 4',
            new DateTimeImmutable('2026-09-08 18:00:00+00:00'),
        );
        $entityManager->persist($otherSignal);
        $entityManager->flush();
        $otherSignalId = $otherSignal->getId();
        self::assertNotNull($otherSignalId);
        $this->otherSignalId = $otherSignalId;
    }

    protected function tearDown(): void
    {
        $storage = dirname(__DIR__, 2).'/var/storage/maintenance';
        foreach (glob($storage.'/*') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/maintenance');

        self::assertResponseRedirects('/login');
    }

    public function testResidentCanCreateAndViewOwnSignal(): void
    {
        $this->client->loginUser($this->resident);
        $crawler = $this->client->request('GET', '/maintenance/signal/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('signal_submit')->form([
            'category' => MaintenanceSignalCategory::ELECTRICAL->value,
            'priority' => MaintenanceSignalPriority::HIGH->value,
            'title' => 'Осветление на етаж 3',
            'description' => 'Двете лампи не светят.',
            'location' => 'Етаж 3',
            'asset_id' => '',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects();
        /** @var list<MaintenanceSignal> $signals */
        $signals = $this->entityManager()->getRepository(MaintenanceSignal::class)->findBy(['submittedBy' => $this->resident], ['createdAt' => 'DESC']);
        self::assertCount(1, $signals);
        $signalId = $signals[0]->getId();
        self::assertNotNull($signalId);

        $this->client->request('GET', '/maintenance/signal/'.$signalId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Осветление на етаж 3');
        self::assertSelectorTextContains('body', 'Висок');
    }

    public function testResidentCannotViewAnotherResidentsSignal(): void
    {
        $this->client->loginUser($this->resident);
        $this->client->request('GET', '/maintenance/signal/'.$this->otherSignalId);

        self::assertResponseStatusCodeSame(404);
    }

    public function testResidentCanUploadAndDownloadOwnAttachment(): void
    {
        $entityManager = $this->entityManager();
        $signal = MaintenanceSignal::open(
            $this->resident,
            MaintenanceSignalCategory::PLUMBING,
            MaintenanceSignalPriority::NORMAL,
            'Теч в мазето',
            'Има теч до водомерите.',
            'Мазе',
            new DateTimeImmutable('2026-09-08 18:00:00+00:00'),
        );
        $entityManager->persist($signal);
        $entityManager->flush();
        $signalId = $signal->getId();
        self::assertNotNull($signalId);

        $source = $this->pngFixture();
        $originalName = basename($source);
        $this->client->loginUser($this->resident);
        $crawler = $this->client->request('GET', '/maintenance/signal/'.$signalId);
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('attachment_submit')->form();
        $form['attachment']->upload($source);
        $this->client->submit($form);
        @unlink($source);

        self::assertResponseRedirects('/maintenance/signal/'.$signalId);
        /** @var list<MaintenanceAttachment> $attachments */
        $attachments = $this->entityManager()->getRepository(MaintenanceAttachment::class)->findAll();
        self::assertCount(1, $attachments);
        $attachmentId = $attachments[0]->getId();
        self::assertNotNull($attachmentId);

        $this->client->request('GET', '/maintenance/attachment/'.$attachmentId.'/download');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'image/png');
        self::assertResponseHeaderSame('content-disposition', 'attachment; filename='.$originalName);
    }

    public function testResidentCannotDownloadAnotherResidentsAttachment(): void
    {
        $entityManager = $this->entityManager();
        $otherSignal = $entityManager->find(MaintenanceSignal::class, $this->otherSignalId);
        self::assertInstanceOf(MaintenanceSignal::class, $otherSignal);
        $attachment = MaintenanceAttachment::record(
            $otherSignal,
            $this->otherResident,
            'photo.png',
            '0123456789abcdef0123456789abcdef.png',
            'image/png',
            100,
            new DateTimeImmutable(),
        );
        $entityManager->persist($attachment);
        $entityManager->flush();
        $attachmentId = $attachment->getId();
        self::assertNotNull($attachmentId);

        $this->client->loginUser($this->resident);
        $this->client->request('GET', '/maintenance/attachment/'.$attachmentId.'/download');

        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidCsrfBlocksSignalCreateAndAttachmentUpload(): void
    {
        $this->client->loginUser($this->resident);

        $this->client->request('POST', '/maintenance/signal/new', [
            '_token' => 'invalid',
            'category' => MaintenanceSignalCategory::OTHER->value,
            'priority' => MaintenanceSignalPriority::NORMAL->value,
            'title' => 'X',
            'description' => 'Y',
            'location' => 'Z',
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/maintenance/signal/'.$this->otherSignalId.'/attachment', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function persistUser(EntityManagerInterface $entityManager, string $email, string $firstName, string $lastName): User
    {
        $person = new Person($firstName, $lastName, email: $email);
        $user = new User($person, $email, 'hash');
        $entityManager->persist($person);
        $entityManager->persist($user);

        return $user;
    }

    private function pngFixture(): string
    {
        $path = sys_get_temp_dir().'/vhod-maintenance-http-'.bin2hex(random_bytes(8)).'.png';
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        self::assertIsString($bytes);
        file_put_contents($path, $bytes);

        return $path;
    }
}
