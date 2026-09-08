<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CommunityComment;
use App\Entity\CommunityPost;
use App\Entity\CommunityReport;
use App\Entity\User;
use App\Enum\CommunityContentStatus;
use App\Enum\CommunityReportStatus;
use App\Service\CommunityModerationService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CommunityModerationController extends AbstractController
{
    private const SOFIA = 'Europe/Sofia';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CommunityModerationService $moderationService,
    ) {
    }

    #[Route('/management/community/moderation', name: 'app_community_moderation', methods: ['GET'])]
    public function index(): Response
    {
        $this->requireModerator();

        /** @var list<CommunityReport> $reports */
        $reports = $this->entityManager->getRepository(CommunityReport::class)->findBy([], ['createdAt' => 'DESC']);
        /** @var list<CommunityPost> $hiddenPosts */
        $hiddenPosts = $this->entityManager->getRepository(CommunityPost::class)->findBy(['status' => CommunityContentStatus::HIDDEN], ['createdAt' => 'DESC']);
        /** @var list<CommunityComment> $hiddenComments */
        $hiddenComments = $this->entityManager->getRepository(CommunityComment::class)->findBy(['status' => CommunityContentStatus::HIDDEN], ['createdAt' => 'DESC']);

        return $this->render('management/community/moderation.html.twig', [
            'reports' => $reports,
            'hidden_posts' => $hiddenPosts,
            'hidden_comments' => $hiddenComments,
            'open_status' => CommunityReportStatus::OPEN,
        ]);
    }

    #[Route('/management/community/post/{id}/visibility', name: 'app_community_moderation_post_visibility', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function postVisibility(int $id, Request $request): Response
    {
        $moderator = $this->requireModerator();
        $post = $this->entityManager->find(CommunityPost::class, $id);
        if (!$post instanceof CommunityPost) {
            throw $this->createNotFoundException('Community post not found.');
        }
        $this->requireCsrf('community_visibility_post_'.$id, $request);

        $visibility = $request->request->getString('visibility');
        if (!in_array($visibility, ['hidden', 'published'], true)) {
            throw $this->createNotFoundException('Unknown visibility state.');
        }

        $this->moderationService->setPostHidden($moderator, $post, 'hidden' === $visibility);
        $this->addFlash('success', 'Видимостта на публикацията е променена.');

        return $this->redirectToRoute('app_community_moderation');
    }

    #[Route('/management/community/comment/{id}/visibility', name: 'app_community_moderation_comment_visibility', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function commentVisibility(int $id, Request $request): Response
    {
        $moderator = $this->requireModerator();
        $comment = $this->entityManager->find(CommunityComment::class, $id);
        if (!$comment instanceof CommunityComment) {
            throw $this->createNotFoundException('Community comment not found.');
        }
        $this->requireCsrf('community_visibility_comment_'.$id, $request);

        $visibility = $request->request->getString('visibility');
        if (!in_array($visibility, ['hidden', 'published'], true)) {
            throw $this->createNotFoundException('Unknown visibility state.');
        }

        $this->moderationService->setCommentHidden($moderator, $comment, 'hidden' === $visibility);
        $this->addFlash('success', 'Видимостта на коментара е променена.');

        return $this->redirectToRoute('app_community_moderation');
    }

    #[Route('/management/community/report/{id}/resolve', name: 'app_community_moderation_report_resolve', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function resolve(int $id, Request $request): Response
    {
        $moderator = $this->requireModerator();
        $report = $this->entityManager->find(CommunityReport::class, $id);
        if (!$report instanceof CommunityReport) {
            throw $this->createNotFoundException('Community report not found.');
        }
        $this->requireCsrf('community_report_resolve_'.$id, $request);

        if (CommunityReportStatus::OPEN !== $report->getStatus()) {
            throw $this->createNotFoundException('Community report is already resolved.');
        }

        $this->moderationService->resolveReport($moderator, $report, new DateTimeImmutable('now', new DateTimeZone(self::SOFIA)));
        $this->addFlash('success', 'Сигналът е отбелязан като приключен.');

        return $this->redirectToRoute('app_community_moderation');
    }

    private function requireModerator(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || (!$this->isGranted('ROLE_MANAGER') && !$this->isGranted('ROLE_ADMIN'))) {
            throw $this->createAccessDeniedException('Community moderation access is required.');
        }

        return $user;
    }

    private function requireCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
