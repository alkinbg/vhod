<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CommunityPollOption;
use App\Entity\CommunityPost;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\CommunityPostType;
use App\Service\CommunityPostService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommunityPostServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CommunityPostService $service;
    private User $user;

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
        $person = new Person('Иван', 'Иванов', email: 'ivan@example.com');
        $this->user = new User($person, 'ivan@example.com', 'hash');
        $entityManager->persist($person);
        $entityManager->persist($this->user);
        $entityManager->flush();
        $service = self::getContainer()->get(CommunityPostService::class);
        self::assertInstanceOf(CommunityPostService::class, $service);
        $this->service = $service;
    }

    protected function tearDown(): void { $this->entityManager->close(); parent::tearDown(); }

    public function testCreatesOrdinaryPost(): void
    {
        $post = $this->service->create($this->user, CommunityPostType::POST, 'Съобщение', 'Добре дошли.', new DateTimeImmutable('2026-09-08 18:00 Europe/Sofia'));
        self::assertNotNull($post->getId());
        self::assertCount(1, $this->entityManager->getRepository(CommunityPost::class)->findAll());
    }

    public function testCreatesInformalPollWithOrderedOptions(): void
    {
        $post = $this->service->create($this->user, CommunityPostType::POLL, 'Домофон', 'Да го сменим ли?', new DateTimeImmutable(), pollOptions: [' Да ', 'Не']);
        $options = $this->entityManager->getRepository(CommunityPollOption::class)->findBy(['poll' => $post], ['position' => 'ASC']);
        self::assertCount(2, $options);
        self::assertSame('Да', $options[0]->getLabel());
        self::assertSame(1, $options[0]->getPosition());
    }

    public function testRejectsDuplicatePollOptionsCaseInsensitively(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create($this->user, CommunityPostType::POLL, 'Анкета', 'Въпрос', new DateTimeImmutable(), pollOptions: ['Да', 'да']);
    }

    public function testRejectsPollOptionsForOrdinaryPost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create($this->user, CommunityPostType::POST, 'Съобщение', 'Текст', new DateTimeImmutable(), pollOptions: ['Да', 'Не']);
    }
}
