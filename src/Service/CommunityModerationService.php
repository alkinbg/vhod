<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CommunityComment;
use App\Entity\CommunityPost;
use App\Entity\CommunityReport;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class CommunityModerationService
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function reportPost(User $reporter, CommunityPost $post, string $reason, DateTimeImmutable $createdAt): CommunityReport
    {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($reporter, $post, $reason, $createdAt): CommunityReport {
            $entityManager->lock($post, LockMode::PESSIMISTIC_WRITE);
            if (!$post->isPublished()) {
                throw new DomainException('Hidden community posts cannot be reported through the resident workflow.');
            }
            $existing = $entityManager->getRepository(CommunityReport::class)->findOneBy(['reporter' => $reporter, 'post' => $post, 'openMarker' => 1]);
            if ($existing instanceof CommunityReport) {
                throw new DomainException('You already have an open report for this post.');
            }
            $report = CommunityReport::forPost($reporter, $post, $reason, $createdAt);
            $entityManager->persist($report);

            return $report;
        });
    }

    public function reportComment(User $reporter, CommunityComment $comment, string $reason, DateTimeImmutable $createdAt): CommunityReport
    {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($reporter, $comment, $reason, $createdAt): CommunityReport {
            $post = $comment->getPost();
            $entityManager->lock($post, LockMode::PESSIMISTIC_WRITE);
            $entityManager->lock($comment, LockMode::PESSIMISTIC_WRITE);
            if (!$post->isPublished() || !$comment->isPublished()) {
                throw new DomainException('Hidden community comments cannot be reported through the resident workflow.');
            }
            $existing = $entityManager->getRepository(CommunityReport::class)->findOneBy(['reporter' => $reporter, 'comment' => $comment, 'openMarker' => 1]);
            if ($existing instanceof CommunityReport) {
                throw new DomainException('You already have an open report for this comment.');
            }
            $report = CommunityReport::forComment($reporter, $comment, $reason, $createdAt);
            $entityManager->persist($report);

            return $report;
        });
    }

    public function setPostHidden(User $moderator, CommunityPost $post, bool $hidden): void
    {
        self::assertModerator($moderator);
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($post, $hidden): void {
            $entityManager->lock($post, LockMode::PESSIMISTIC_WRITE);
            $hidden ? $post->hide() : $post->publishAgain();
        });
    }

    public function setCommentHidden(User $moderator, CommunityComment $comment, bool $hidden): void
    {
        self::assertModerator($moderator);
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($comment, $hidden): void {
            $entityManager->lock($comment->getPost(), LockMode::PESSIMISTIC_WRITE);
            $entityManager->lock($comment, LockMode::PESSIMISTIC_WRITE);
            $hidden ? $comment->hide() : $comment->publishAgain();
        });
    }

    public function resolveReport(User $moderator, CommunityReport $report, DateTimeImmutable $resolvedAt): void
    {
        self::assertModerator($moderator);
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($moderator, $report, $resolvedAt): void {
            $entityManager->lock($report, LockMode::PESSIMISTIC_WRITE);
            $report->resolve($moderator, $resolvedAt);
        });
    }

    private static function assertModerator(User $user): void
    {
        $roles = $user->getRoles();
        if (!in_array('ROLE_MANAGER', $roles, true) && !in_array('ROLE_ADMIN', $roles, true)) {
            throw new DomainException('Community moderation access is required.');
        }
    }
}
