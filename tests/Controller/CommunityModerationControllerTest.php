<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CommunityComment;
use App\Entity\CommunityPost;
use App\Entity\CommunityReport;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\CommunityContentStatus;
use App\Enum\CommunityPostType;
use App\Enum\CommunityReportStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CommunityModerationControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $resident;
    private User $manager;
    private User $controller;

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
        $this->resident = $this->persistUser('resident@example.com', []);
        $this->manager = $this->persistUser('manager@example.com', ['ROLE_MANAGER']);
        $this->controller = $this->persistUser('controller@example.com', ['ROLE_CONTROLLER']);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function testResidentAndControllerCannotOpenModeration(): void
    {
        foreach ([$this->resident, $this->controller] as $user) {
            $this->client->loginUser($user);
            $this->client->request('GET', '/management/community/moderation');
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testManagerCanHideUnhidePostAndResolveReport(): void
    {
        $post = CommunityPost::publish($this->resident, CommunityPostType::POST, 'Спорна тема', 'Текст', new DateTimeImmutable());
        $report = CommunityReport::forPost($this->resident, $post, 'Нужен е преглед.', new DateTimeImmutable());
        $this->entityManager->persist($post); $this->entityManager->persist($report); $this->entityManager->flush();
        $postId = $post->getId(); $reportId = $report->getId(); self::assertNotNull($postId); self::assertNotNull($reportId);
        $this->client->loginUser($this->manager);
        $crawler = $this->client->request('GET', '/management/community/moderation');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('post_visibility_'.$postId)->form());
        self::assertResponseRedirects('/management/community/moderation');
        $this->entityManager->clear();
        $storedPost = $this->entityManager->find(CommunityPost::class, $postId);
        self::assertInstanceOf(CommunityPost::class, $storedPost);
        self::assertSame(CommunityContentStatus::HIDDEN, $storedPost->getStatus());
        $resident = $this->entityManager->find(User::class, $this->resident->getId());
        self::assertInstanceOf(User::class, $resident);
        $this->client->loginUser($resident);
        $this->client->request('GET', '/community/'.$postId);
        self::assertResponseStatusCodeSame(404);
        $manager = $this->entityManager->find(User::class, $this->manager->getId());
        self::assertInstanceOf(User::class, $manager);
        $this->client->loginUser($manager);
        $crawler = $this->client->request('GET', '/management/community/moderation');
        $this->client->submit($crawler->selectButton('post_visibility_'.$postId)->form());
        self::assertResponseRedirects('/management/community/moderation');
        $crawler = $this->client->request('GET', '/management/community/moderation');
        $this->client->submit($crawler->selectButton('report_resolve_'.$reportId)->form());
        self::assertResponseRedirects('/management/community/moderation');
        $this->entityManager->clear();
        $storedPost = $this->entityManager->find(CommunityPost::class, $postId);
        $storedReport = $this->entityManager->find(CommunityReport::class, $reportId);
        self::assertInstanceOf(CommunityPost::class, $storedPost); self::assertInstanceOf(CommunityReport::class, $storedReport);
        self::assertSame(CommunityContentStatus::PUBLISHED, $storedPost->getStatus());
        self::assertSame(CommunityReportStatus::RESOLVED, $storedReport->getStatus());
        self::assertNull($storedReport->getOpenMarker());
    }

    public function testManagerCanHideReportedCommentWithoutHidingPost(): void
    {
        $post = CommunityPost::publish($this->resident, CommunityPostType::POST, 'Тема', 'Текст', new DateTimeImmutable());
        $comment = CommunityComment::write($post, $this->resident, 'Спорен коментар', new DateTimeImmutable());
        $report = CommunityReport::forComment($this->resident, $comment, 'Проверете коментара.', new DateTimeImmutable());
        foreach ([$post, $comment, $report] as $entity) { $this->entityManager->persist($entity); }
        $this->entityManager->flush();
        $postId = $post->getId(); $commentId = $comment->getId(); self::assertNotNull($postId); self::assertNotNull($commentId);
        $this->client->loginUser($this->manager);
        $crawler = $this->client->request('GET', '/management/community/moderation');
        $this->client->submit($crawler->selectButton('comment_visibility_'.$commentId)->form());
        self::assertResponseRedirects('/management/community/moderation');
        $this->entityManager->clear();
        $storedComment = $this->entityManager->find(CommunityComment::class, $commentId);
        $storedPost = $this->entityManager->find(CommunityPost::class, $postId);
        self::assertInstanceOf(CommunityComment::class, $storedComment); self::assertInstanceOf(CommunityPost::class, $storedPost);
        self::assertSame(CommunityContentStatus::HIDDEN, $storedComment->getStatus());
        self::assertSame(CommunityContentStatus::PUBLISHED, $storedPost->getStatus());
    }

    public function testInvalidCsrfBlocksAllModerationMutationFamilies(): void
    {
        $post = CommunityPost::publish($this->resident, CommunityPostType::POST, 'Тема', 'Текст', new DateTimeImmutable());
        $comment = CommunityComment::write($post, $this->resident, 'Коментар', new DateTimeImmutable());
        $report = CommunityReport::forPost($this->resident, $post, 'Причина', new DateTimeImmutable());
        foreach ([$post, $comment, $report] as $entity) { $this->entityManager->persist($entity); }
        $this->entityManager->flush();
        $postId = $post->getId(); $commentId = $comment->getId(); $reportId = $report->getId();
        self::assertNotNull($postId); self::assertNotNull($commentId); self::assertNotNull($reportId);
        $this->client->loginUser($this->manager);
        $requests = [
            ['/management/community/post/'.$postId.'/visibility', ['_token' => 'invalid', 'visibility' => 'hidden']],
            ['/management/community/comment/'.$commentId.'/visibility', ['_token' => 'invalid', 'visibility' => 'hidden']],
            ['/management/community/report/'.$reportId.'/resolve', ['_token' => 'invalid']],
        ];
        foreach ($requests as [$path, $parameters]) { $this->client->request('POST', $path, $parameters); self::assertResponseStatusCodeSame(403); }
        self::assertSame(CommunityContentStatus::PUBLISHED, $post->getStatus());
        self::assertSame(CommunityContentStatus::PUBLISHED, $comment->getStatus());
        self::assertSame(CommunityReportStatus::OPEN, $report->getStatus());
    }

    private function persistUser(string $email, array $roles): User
    {
        $person = new Person('Тест', ucfirst(strstr($email, '@', true) ?: 'User'), email: $email);
        $user = new User($person, $email, 'hash'); $user->setRoles($roles);
        $this->entityManager->persist($person); $this->entityManager->persist($user);
        return $user;
    }
}
