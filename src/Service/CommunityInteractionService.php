<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CommunityComment;
use App\Entity\CommunityPollOption;
use App\Entity\CommunityPollVote;
use App\Entity\CommunityPost;
use App\Entity\CommunityReaction;
use App\Entity\User;
use App\Enum\CommunityPostType;
use App\Enum\CommunityReactionType;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use LogicException;

final readonly class CommunityInteractionService
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function addComment(User $author, CommunityPost $post, string $body, DateTimeImmutable $createdAt): CommunityComment
    {
        $result = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($author, $post, $body, $createdAt): CommunityComment {
            $entityManager->lock($post, LockMode::PESSIMISTIC_WRITE);
            self::assertPublished($post);
            $comment = CommunityComment::write($post, $author, $body, $createdAt);
            $entityManager->persist($comment);
            return $comment;
        });
        if (!$result instanceof CommunityComment) {
            throw new LogicException('Community comment transaction returned an unexpected result.');
        }
        return $result;
    }

    public function toggleReaction(User $user, CommunityPost $post, CommunityReactionType $type, DateTimeImmutable $reactedAt): ?CommunityReaction
    {
        $result = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($user, $post, $type, $reactedAt): ?CommunityReaction {
            $entityManager->lock($post, LockMode::PESSIMISTIC_WRITE);
            self::assertPublished($post);
            $existing = $entityManager->getRepository(CommunityReaction::class)->findOneBy(['post' => $post, 'user' => $user]);
            if ($existing instanceof CommunityReaction) {
                if ($existing->getType() === $type) {
                    $entityManager->remove($existing);
                    return null;
                }
                $existing->changeType($type, $reactedAt);
                return $existing;
            }
            $reaction = CommunityReaction::react($post, $user, $type, $reactedAt);
            $entityManager->persist($reaction);
            return $reaction;
        });
        if (null !== $result && !$result instanceof CommunityReaction) {
            throw new LogicException('Community reaction transaction returned an unexpected result.');
        }
        return $result;
    }

    public function castPollVote(User $user, CommunityPost $poll, CommunityPollOption $option, DateTimeImmutable $votedAt): CommunityPollVote
    {
        $result = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($user, $poll, $option, $votedAt): CommunityPollVote {
            $entityManager->lock($poll, LockMode::PESSIMISTIC_WRITE);
            self::assertPublished($poll);
            if (CommunityPostType::POLL !== $poll->getType()) {
                throw new DomainException('Votes may be cast only for informal poll posts.');
            }
            if ($option->getPoll() !== $poll) {
                throw new DomainException('Selected option does not belong to this poll.');
            }
            $existing = $entityManager->getRepository(CommunityPollVote::class)->findOneBy(['poll' => $poll, 'voter' => $user]);
            if ($existing instanceof CommunityPollVote) {
                $existing->changeOption($option, $votedAt);
                return $existing;
            }
            $vote = CommunityPollVote::cast($poll, $option, $user, $votedAt);
            $entityManager->persist($vote);
            return $vote;
        });
        if (!$result instanceof CommunityPollVote) {
            throw new LogicException('Community vote transaction returned an unexpected result.');
        }
        return $result;
    }

    private static function assertPublished(CommunityPost $post): void
    {
        if (!$post->isPublished()) {
            throw new DomainException('Hidden community content cannot be interacted with.');
        }
    }
}
