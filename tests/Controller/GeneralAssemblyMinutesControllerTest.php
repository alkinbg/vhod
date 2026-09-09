<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AssemblyResolution;
use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyDecisionKind;
use App\Enum\AssemblyResolutionResult;
use App\Enum\AssemblyVoteDenominator;
use App\Enum\DocumentCategory;
use App\Enum\GeneralAssemblyStatus;
use App\Enum\MajorityComparison;
use App\Value\AssemblyMajorityRuleSnapshot;
use App\Value\AssemblyResolutionCalculation;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GeneralAssemblyMinutesControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $managerId;
    private int $residentId;
    private int $assemblyId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = $this->entityManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->clearStorage();

        $managerPerson = new Person('Мария', 'Управител', email: 'manager-minutes-http@example.com');
        $manager = new User($managerPerson, 'manager-minutes-http@example.com', 'hash');
        $manager->setRoles(['ROLE_MANAGER']);
        $residentPerson = new Person('Румен', 'Живущ', email: 'resident-minutes-http@example.com');
        $resident = new User($residentPerson, 'resident-minutes-http@example.com', 'hash');

        $assembly = GeneralAssembly::draft(
            'Общо събрание — HTTP протокол',
            new DateTimeImmutable('2026-09-09T17:00:00Z'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-09'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Мария Управител',
            $manager,
            $manager,
            new DateTimeImmutable('2026-09-09T08:00:00Z'),
        );
        $item = $assembly->addAgendaItem(
            1,
            'Избор на изпълнител',
            null,
            'Да бъде избран изпълнител А.',
            AssemblyDecisionKind::ORDINARY,
            new AssemblyMajorityRuleSnapshot(
                'minutes-http-majority',
                AssemblyVoteDenominator::REPRESENTED_AT_MEETING,
                '50',
                MajorityComparison::GREATER_THAN,
                'ЗУЕС — тестово основание',
                '2026-09-09',
            ),
        );

        foreach ([$managerPerson, $manager, $residentPerson, $resident, $assembly, $item] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $assembly->convene($manager, new DateTimeImmutable('2026-09-09T16:00:00Z'));
        $assembly->start($manager, new DateTimeImmutable('2026-09-09T17:00:00Z'));
        $item->open('Да бъде избран изпълнител А.', new DateTimeImmutable('2026-09-09T17:05:00Z'));
        $resolution = AssemblyResolution::record(
            $item,
            new AssemblyResolutionCalculation(
                '60',
                '10',
                '5',
                '75',
                '37.5',
                AssemblyResolutionResult::ACCEPTED,
                'Решението е прието.',
            ),
            $manager,
            new DateTimeImmutable('2026-09-09T17:45:00Z'),
        );
        $item->resolve(new DateTimeImmutable('2026-09-09T17:45:00Z'));
        $assembly->close($manager, new DateTimeImmutable('2026-09-09T18:00:00Z'));
        $em->persist($resolution);
        $em->flush();

        self::assertNotNull($manager->getId());
        self::assertNotNull($resident->getId());
        self::assertNotNull($assembly->getId());
        $this->managerId = $manager->getId();
        $this->residentId = $resident->getId();
        $this->assemblyId = $assembly->getId();
    }

    protected function tearDown(): void
    {
        $this->clearStorage();
        parent::tearDown();
    }

    public function testMinutesPreviewRequiresManagementAndShowsOfficialDraft(): void
    {
        $this->client->request('GET', $this->previewUrl());
        self::assertResponseRedirects('/login');

        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($this->user($this->residentId));
        $this->client->request('GET', $this->previewUrl());
        self::assertResponseStatusCodeSame(403);

        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($this->user($this->managerId));
        $this->client->request('GET', $this->previewUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Преглед на протокола');
        self::assertSelectorTextContains('body', 'Официално общо събрание');
    }

    public function testMetadataAndFinalizeActionsAreCsrfProtectedAndPersistFinalMinutes(): void
    {
        $this->client->loginUser($this->user($this->managerId));
        $this->client->request('POST', $this->metadataUrl(), [
            '_token' => 'invalid',
            'chairperson_name' => 'Иван Председател',
            'secretary_name' => 'Елена Протоколчик',
        ]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', $this->previewUrl());
        $metadataForm = $crawler->selectButton('assembly_minutes_metadata_'.$this->assemblyId)->form([
            'chairperson_name' => 'Иван Председател',
            'secretary_name' => 'Елена Протоколчик',
            'formal_notes' => 'Проверен проект на протокола.',
        ]);
        $this->client->submit($metadataForm);
        self::assertResponseRedirects($this->previewUrl());

        $assembly = $this->entityManager()->find(GeneralAssembly::class, $this->assemblyId);
        self::assertInstanceOf(GeneralAssembly::class, $assembly);
        self::assertSame('Иван Председател', $assembly->getChairpersonNameSnapshot());
        self::assertSame('Елена Протоколчик', $assembly->getSecretaryNameSnapshot());

        $this->client->request('POST', $this->finalizeUrl(), ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', $this->previewUrl());
        $finalizeForm = $crawler->selectButton('assembly_minutes_finalize_'.$this->assemblyId)->form();
        $this->client->submit($finalizeForm);
        self::assertResponseRedirects($this->previewUrl());

        $assembly = $this->entityManager()->find(GeneralAssembly::class, $this->assemblyId);
        self::assertInstanceOf(GeneralAssembly::class, $assembly);
        self::assertSame(GeneralAssemblyStatus::MINUTES_FINALIZED, $assembly->getStatus());
        self::assertNotNull($assembly->getMinutesDocument());
        self::assertSame(DocumentCategory::MEETING_MINUTES, $assembly->getMinutesDocument()?->getCategory());
        self::assertFileExists($this->storageDirectory().'/'.$assembly->getMinutesDocument()?->getStorageName());
        self::assertCount(1, $this->entityManager()->getRepository(Document::class)->findBy(['category' => DocumentCategory::MEETING_MINUTES]));
    }

    public function testResidentCannotFinalizeMinutes(): void
    {
        $this->client->loginUser($this->user($this->residentId));
        $this->client->request('POST', $this->finalizeUrl(), ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $assembly = $this->entityManager()->find(GeneralAssembly::class, $this->assemblyId);
        self::assertInstanceOf(GeneralAssembly::class, $assembly);
        self::assertSame(GeneralAssemblyStatus::CLOSED, $assembly->getStatus());
    }

    private function user(int $id): User
    {
        $user = $this->entityManager()->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function previewUrl(): string
    {
        return '/management/assembly/'.$this->assemblyId.'/minutes';
    }

    private function metadataUrl(): string
    {
        return $this->previewUrl().'/metadata';
    }

    private function finalizeUrl(): string
    {
        return $this->previewUrl().'/finalize';
    }

    private function storageDirectory(): string
    {
        return dirname(__DIR__, 2).'/var/storage/documents';
    }

    private function clearStorage(): void
    {
        $directory = $this->storageDirectory();
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
    }
}
