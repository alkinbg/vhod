<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\BookChangeDeclaration;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Entity\User;
use App\Enum\BookChangeType;
use App\Enum\BookDeclarationStatus;
use App\Enum\UnitRelationType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CondominiumBookControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $resident;
    private User $manager;
    private Unit $residentUnit;
    private Unit $otherUnit;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $tool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $residentPerson = new Person('Иван', 'Иванов', email: 'ivan@example.com', phone: '+359888111111');
        $this->resident = new User($residentPerson, 'ivan@example.com', 'hash');

        $managerPerson = new Person('Мария', 'Петрова', email: 'manager@example.com');
        $this->manager = new User($managerPerson, 'manager@example.com', 'hash');
        $this->manager->setRoles(['ROLE_MANAGER']);

        $otherPerson = new Person('Петър', 'Петров', email: 'petar@example.com');

        $this->residentUnit = new Unit('12', floor: 3, builtArea: '72.50', idealParts: '5.2500');
        $this->otherUnit = new Unit('13', floor: 3, builtArea: '68.00', idealParts: '4.9000');

        $residentRelation = new UnitRelation(
            $residentPerson,
            $this->residentUnit,
            UnitRelationType::OWNER,
            new DateTimeImmutable('2026-01-01'),
            '100.0000',
        );
        $otherRelation = new UnitRelation(
            $otherPerson,
            $this->otherUnit,
            UnitRelationType::OWNER,
            new DateTimeImmutable('2026-01-01'),
            '100.0000',
        );

        foreach ([
            $residentPerson,
            $this->resident,
            $managerPerson,
            $this->manager,
            $otherPerson,
            $this->residentUnit,
            $this->otherUnit,
            $residentRelation,
            $otherRelation,
        ] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function testResidentBookShowsOnlyOwnActiveUnit(): void
    {
        $this->client->loginUser($this->resident);
        $crawler = $this->client->request('GET', '/book');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Моята книга');
        self::assertStringContainsString('Обект 12', $crawler->filter('main')->text());
        self::assertStringNotContainsString('Обект 13', $crawler->filter('main')->text());
        self::assertSelectorExists('a[href="/book/declaration/contact_update"]');
    }

    public function testResidentCannotOpenManagementBook(): void
    {
        $this->client->loginUser($this->resident);
        $this->client->request('GET', '/management/book');

        self::assertResponseStatusCodeSame(403);
    }

    public function testManagerCanSeeAllUnitsAndPendingDeclarations(): void
    {
        $declaration = BookChangeDeclaration::submit(
            $this->residentUnit,
            $this->resident,
            BookChangeType::CONTACT_UPDATE,
            ['phone' => '+359888222222'],
            new DateTimeImmutable('2026-09-08 00:30:00', new DateTimeZone('Europe/Sofia')),
        );
        $this->entityManager->persist($declaration);
        $this->entityManager->flush();

        $this->client->loginUser($this->manager);
        $crawler = $this->client->request('GET', '/management/book');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Книга на етажната собственост');
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('Обект 12', $text);
        self::assertStringContainsString('Обект 13', $text);
        self::assertStringContainsString('За преглед', $text);
    }

    public function testResidentCanSubmitAnimalDeclaration(): void
    {
        $this->client->loginUser($this->resident);
        $crawler = $this->client->request('GET', '/book/declaration/animal');

        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Изпрати декларацията')->form([
            'species' => 'куче',
            'count' => '1',
            'passport' => 'BG-123',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/book');

        $declarations = $this->entityManager->getRepository(BookChangeDeclaration::class)->findAll();
        self::assertCount(1, $declarations);
        self::assertSame(BookChangeType::ANIMAL, $declarations[0]->getType());
        self::assertSame(BookDeclarationStatus::SUBMITTED, $declarations[0]->getStatus());
        self::assertSame('BG-123', $declarations[0]->getPayload()['passport']);
    }

    public function testManagerCanAcceptPendingDeclarationThroughPostAction(): void
    {
        $declaration = BookChangeDeclaration::submit(
            $this->residentUnit,
            $this->resident,
            BookChangeType::CONTACT_UPDATE,
            ['phone' => '+359888333333'],
            new DateTimeImmutable('2026-09-08 00:30:00', new DateTimeZone('Europe/Sofia')),
        );
        $this->entityManager->persist($declaration);
        $this->entityManager->flush();

        $declarationId = $declaration->getId();
        $residentId = $this->resident->getId();
        self::assertNotNull($declarationId);
        self::assertNotNull($residentId);

        $this->client->loginUser($this->manager);
        $crawler = $this->client->request('GET', '/management/book');
        $form = $crawler->selectButton('Одобри')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/management/book');

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $storedDeclaration = $entityManager->find(BookChangeDeclaration::class, $declarationId);
        $storedResident = $entityManager->find(User::class, $residentId);
        self::assertInstanceOf(BookChangeDeclaration::class, $storedDeclaration);
        self::assertInstanceOf(User::class, $storedResident);
        self::assertSame(BookDeclarationStatus::ACCEPTED, $storedDeclaration->getStatus());
        self::assertSame('+359888333333', $storedResident->getPerson()->getPhone());
    }

    public function testManagerHasPrintableBookView(): void
    {
        $this->client->loginUser($this->manager);
        $crawler = $this->client->request('GET', '/management/book/print');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Книга на етажната собственост');
        self::assertStringContainsString('Обект 12', $crawler->filter('body')->text());
        self::assertStringContainsString('Иван Иванов', $crawler->filter('body')->text());
    }
}
