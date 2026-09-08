<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CommunityComment;
use App\Entity\CommunityPollOption;
use App\Entity\CommunityPollVote;
use App\Entity\CommunityPost;
use App\Entity\CommunityReaction;
use App\Entity\CommunityReport;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\CommunityPostType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CommunityControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $resident;
    private User $otherResident;

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
        $this->resident = $this->persistUser('resident@example.com', 'Иван', 'Иванов');
        $this->otherResident = $this->persistUser('neighbour@example.com', 'Мария', 'Петрова');
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/community');
        self::assertResponseRedirects('/login');
    }

    public function testResidentCanCreatePostAndReadItFromFeed(): void
    {
        $this->client->loginUser($this->resident);
        $crawler = $this->client->request('GET', '/community/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Публикувай')->form([
            'type' => CommunityPostType::IDEA->value,
            'title' => 'Нова пейка пред входа',
            'body' => 'Предлагам да обсъдим поставяне на пейка до входа.',
            'starts_at' => '',
            'ends_at' => '',
            'poll_options' => '',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        /** @var list<CommunityPost> $posts */
        $posts = $this->entityManager->getRepository(CommunityPost::class)->findAll();
        self::assertCount(1, $posts);
        self::assertSame(CommunityPostType::IDEA, $posts[0]->getType());

        $this->client->request('GET', '/community');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Нова пейка пред входа');
        self::assertSelectorTextContains('body', 'Неофициално съдържание');
    }

    public function testResidentCanCommentReactReportAndChangePollVote(): void
    {
        $post = CommunityPost::publish(
            $this->otherResident,
            CommunityPostType::POST,
            'Почистване в събота',
            'Нека се организираме за двора.',
            new DateTimeImmutable('2026-09-08 15:00:00 Europe/Sofia'),
        );
        $poll = CommunityPost::publish(
            $this->otherResident,
            CommunityPostType::POLL,
            'Да боядисаме ли входа?',
            'Неформална анкета за мнение.',
            new DateTimeImmutable('2026-09-08 16:00:00 Europe/Sofia'),
        );
        $firstOption = CommunityPollOption::create($poll, 'Да', 1);
        $secondOption = CommunityPollOption::create($poll, 'Не', 2);
        foreach ([$post, $poll, $firstOption, $secondOption] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $postId = $post->getId();
        $pollId = $poll->getId();
        $firstOptionId = $firstOption->getId();
        $secondOptionId = $secondOption->getId();
        self::assertNotNull($postId);
        self::assertNotNull($pollId);
        self::assertNotNull($firstOptionId);
        self::assertNotNull($secondOptionId);

        $this->client->loginUser($this->resident);

        $crawler = $this->client->request('GET', '/community/'.$postId);
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('comment_submit')->form(['body' => 'Ще се включа.']));
        self::assertResponseRedirects('/community/'.$postId);

        $crawler = $this->client->request('GET', '/community/'.$postId);
        $this->client->submit($crawler->selectButton('reaction_like')->form());
        self::assertResponseRedirects('/community/'.$postId);

        $crawler = $this->client->request('GET', '/community/'.$postId);
        $this->client->submit($crawler->selectButton('post_report_submit')->form(['reason' => 'Искам управителят да провери съдържанието.']));
        self::assertResponseRedirects('/community/'.$postId);

        $crawler = $this->client->request('GET', '/community/'.$postId);
        $this->client->submit($crawler->selectButton('Сигнализирай коментара')->form(['reason' => 'Проверка на коментара.']));
        self::assertResponseRedirects('/community/'.$postId);

        $crawler = $this->client->request('GET', '/community/'.$pollId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'няма сила на решение на Общото събрание');
        $this->client->submit($crawler->selectButton('poll_vote_submit')->form(['option_id' => (string) $firstOptionId]));
        self::assertResponseRedirects('/community/'.$pollId);

        $crawler = $this->client->request('GET', '/community/'.$pollId);
        $this->client->submit($crawler->selectButton('poll_vote_submit')->form(['option_id' => (string) $secondOptionId]));
        self::assertResponseRedirects('/community/'.$pollId);

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $managedPoll = $entityManager->find(CommunityPost::class, $pollId);
        self::assertInstanceOf(CommunityPost::class, $managedPoll);

        self::assertCount(1, $entityManager->getRepository(CommunityComment::class)->findBy(['post' => $postId]));
        self::assertCount(1, $entityManager->getRepository(CommunityReaction::class)->findBy(['post' => $postId]));
        self::assertCount(2, $entityManager->getRepository(CommunityReport::class)->findAll());

        /** @var list<CommunityPollVote> $votes */
        $votes = $entityManager->getRepository(CommunityPollVote::class)->findBy(['poll' => $managedPoll]);
        self::assertCount(1, $votes);
        self::assertSame($secondOptionId, $votes[0]->getOption()->getId());
    }

    public function testInvalidCsrfBlocksEveryResidentMutationFamily(): void
    {
        $post = CommunityPost::publish($this->otherResident, CommunityPostType::POST, 'Тема', 'Текст', new DateTimeImmutable());
        $poll = CommunityPost::publish($this->otherResident, CommunityPostType::POLL, 'Анкета', 'Текст', new DateTimeImmutable());
        $comment = CommunityComment::write($post, $this->otherResident, 'Коментар', new DateTimeImmutable());
        $option = CommunityPollOption::create($poll, 'Да', 1);
        foreach ([$post, $poll, $comment, $option] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $postId = $post->getId();
        $pollId = $poll->getId();
        $commentId = $comment->getId();
        $optionId = $option->getId();
        self::assertNotNull($postId);
        self::assertNotNull($pollId);
        self::assertNotNull($commentId);
        self::assertNotNull($optionId);

        $this->client->loginUser($this->resident);
        $requests = [
            ['/community/new', ['_token' => 'invalid', 'type' => 'post', 'title' => 'X', 'body' => 'Y']],
            ['/community/'.$postId.'/comment', ['_token' => 'invalid', 'body' => 'X']],
            ['/community/'.$postId.'/reaction', ['_token' => 'invalid', 'type' => 'like']],
            ['/community/'.$pollId.'/poll-vote', ['_token' => 'invalid', 'option_id' => (string) $optionId]],
            ['/community/'.$postId.'/report', ['_token' => 'invalid', 'reason' => 'X']],
            ['/community/comment/'.$commentId.'/report', ['_token' => 'invalid', 'reason' => 'X']],
        ];
        foreach ($requests as [$path, $parameters]) {
            $this->client->request('POST', $path, $parameters);
            self::assertResponseStatusCodeSame(403);
        }

        self::assertCount(0, $this->entityManager->getRepository(CommunityReaction::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(CommunityPollVote::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(CommunityReport::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(CommunityComment::class)->findAll());
    }

    /** @param list<string> $roles */
    private function persistUser(string $email, string $firstName, string $lastName, array $roles = []): User
    {
        $person = new Person($firstName, $lastName, email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);
        $this->entityManager->persist($person);
        $this->entityManager->persist($user);

        return $user;
    }
}
