<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CommunityPost;
use App\Entity\CommunityReport;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\CommunityPostType;
use App\Enum\CommunityReportStatus;
use App\Service\CommunityModerationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommunityModerationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CommunityModerationService $service;
    private User $resident;
    private User $manager;
    private User $cashier;
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
        $this->resident = $this->user('resident@example.com');
        $this->manager = $this->user('manager@example.com', ['ROLE_MANAGER']);
        $this->cashier = $this->user('cashier@example.com', ['ROLE_CASHIER']);
        $this->post = CommunityPost::publish($this->resident, CommunityPostType::POST, 'Пост', 'Текст', new DateTimeImmutable());
        foreach ([$this->resident, $this->manager, $this->cashier] as $user) { $entityManager->persist($user->getPerson()); $entityManager->persist($user); }
        $entityManager->persist($this->post);
        $entityManager->flush();
        $service = self::getContainer()->get(CommunityModerationService::class);
        self::assertInstanceOf(CommunityModerationService::class, $service);
        $this->service = $service;
    }

    protected function tearDown(): void { $this->entityManager->close(); parent::tearDown(); }

    public function testDuplicateOpenReportIsRejectedButCanReportAgainAfterResolution(): void
    {
        $report = $this->service->reportPost($this->resident, $this->post, 'Причина', new DateTimeImmutable());
        $this->service->resolveReport($this->manager, $report, new DateTimeImmutable('+1 hour'));
        $second = $this->service->reportPost($this->resident, $this->post, 'Нова причина', new DateTimeImmutable('+2 hours'));
        self::assertSame(CommunityReportStatus::OPEN, $second->getStatus());
        self::assertCount(2, $this->entityManager->getRepository(CommunityReport::class)->findAll());
    }

    public function testDuplicateOpenReportBeforeResolutionIsRejected(): void
    {
        $this->service->reportPost($this->resident, $this->post, 'Причина', new DateTimeImmutable());
        $this->expectException(DomainException::class);
        $this->service->reportPost($this->resident, $this->post, 'Пак', new DateTimeImmutable('+1 minute'));
    }

    public function testManagerCanHideAndUnhidePost(): void
    {
        $this->service->setPostHidden($this->manager, $this->post, true);
        self::assertFalse($this->post->isPublished());
        $this->service->setPostHidden($this->manager, $this->post, false);
        self::assertTrue($this->post->isPublished());
    }

    public function testCashierCannotModerate(): void
    {
        $this->expectException(DomainException::class);
        $this->service->setPostHidden($this->cashier, $this->post, true);
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $person = new Person('Тест', ucfirst(strstr($email, '@', true) ?: 'User'), email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);
        return $user;
    }
}
