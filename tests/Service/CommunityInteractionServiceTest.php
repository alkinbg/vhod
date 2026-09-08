<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CommunityComment;
use App\Entity\CommunityPollOption;
use App\Entity\CommunityPollVote;
use App\Entity\CommunityPost;
use App\Entity\CommunityReaction;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\CommunityPostType;
use App\Enum\CommunityReactionType;
use App\Service\CommunityInteractionService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommunityInteractionServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CommunityInteractionService $service;
    private User $user;
    private CommunityPost $post;

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
        $this->post = CommunityPost::publish($this->user, CommunityPostType::POST, 'Пост', 'Текст', new DateTimeImmutable());
        foreach ([$person, $this->user, $this->post] as $entity) { $entityManager->persist($entity); }
        $entityManager->flush();
        $service = self::getContainer()->get(CommunityInteractionService::class);
        self::assertInstanceOf(CommunityInteractionService::class, $service);
        $this->service = $service;
    }

    protected function tearDown(): void { $this->entityManager->close(); parent::tearDown(); }

    public function testAddsCommentToPublishedPost(): void
    {
        $comment = $this->service->addComment($this->user, $this->post, 'Коментар', new DateTimeImmutable());
        self::assertNotNull($comment->getId());
        self::assertCount(1, $this->entityManager->getRepository(CommunityComment::class)->findAll());
    }

    public function testReactionTogglesAndChangesWithoutDuplicates(): void
    {
        $reaction = $this->service->toggleReaction($this->user, $this->post, CommunityReactionType::LIKE, new DateTimeImmutable());
        self::assertInstanceOf(CommunityReaction::class, $reaction);
        $changed = $this->service->toggleReaction($this->user, $this->post, CommunityReactionType::THANKS, new DateTimeImmutable('+1 minute'));
        self::assertInstanceOf(CommunityReaction::class, $changed);
        self::assertSame(CommunityReactionType::THANKS, $changed->getType());
        self::assertCount(1, $this->entityManager->getRepository(CommunityReaction::class)->findAll());
        $removed = $this->service->toggleReaction($this->user, $this->post, CommunityReactionType::THANKS, new DateTimeImmutable('+2 minutes'));
        self::assertNull($removed);
        self::assertCount(0, $this->entityManager->getRepository(CommunityReaction::class)->findAll());
    }

    public function testVoteChangeKeepsSingleRow(): void
    {
        $poll = CommunityPost::publish($this->user, CommunityPostType::POLL, 'Анкета', 'Въпрос', new DateTimeImmutable());
        $yes = CommunityPollOption::create($poll, 'Да', 1);
        $no = CommunityPollOption::create($poll, 'Не', 2);
        foreach ([$poll, $yes, $no] as $entity) { $this->entityManager->persist($entity); }
        $this->entityManager->flush();
        $vote = $this->service->castPollVote($this->user, $poll, $yes, new DateTimeImmutable());
        self::assertSame($yes, $vote->getOption());
        $changed = $this->service->castPollVote($this->user, $poll, $no, new DateTimeImmutable('+1 minute'));
        self::assertSame($no, $changed->getOption());
        self::assertCount(1, $this->entityManager->getRepository(CommunityPollVote::class)->findAll());
    }

    public function testHiddenPostRejectsInteractions(): void
    {
        $this->post->hide();
        $this->entityManager->flush();
        $this->expectException(DomainException::class);
        $this->service->addComment($this->user, $this->post, 'Не трябва', new DateTimeImmutable());
    }
}
