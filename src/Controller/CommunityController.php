<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CommunityComment;
use App\Entity\CommunityPollOption;
use App\Entity\CommunityPollVote;
use App\Entity\CommunityPost;
use App\Entity\CommunityReaction;
use App\Entity\User;
use App\Enum\CommunityContentStatus;
use App\Enum\CommunityPostType;
use App\Enum\CommunityReactionType;
use App\Service\CommunityInteractionService;
use App\Service\CommunityModerationService;
use App\Service\CommunityPostService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CommunityController extends AbstractController
{
    private const SOFIA = 'Europe/Sofia';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CommunityPostService $postService,
        private readonly CommunityInteractionService $interactionService,
        private readonly CommunityModerationService $moderationService,
    ) {
    }

    #[Route('/community', name: 'app_community_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireCommunityUser();

        $type = null;
        $typeInput = $request->query->getString('type');
        if ('' !== $typeInput) {
            $type = CommunityPostType::tryFrom($typeInput);
            if (!$type instanceof CommunityPostType) {
                throw $this->createNotFoundException('Unknown community post type.');
            }
        }

        $criteria = ['status' => CommunityContentStatus::PUBLISHED];
        if ($type instanceof CommunityPostType) {
            $criteria['type'] = $type;
        }

        /** @var list<CommunityPost> $posts */
        $posts = $this->entityManager->getRepository(CommunityPost::class)->findBy($criteria, ['createdAt' => 'DESC'], 50);

        return $this->render('community/index.html.twig', [
            'posts' => $posts,
            'types' => CommunityPostType::cases(),
            'selected_type' => $type,
            'stats' => $this->buildPostStats($posts),
        ]);
    }

    #[Route('/community/new', name: 'app_community_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = $this->requireCommunityUser();
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('community_post_create', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            try {
                $type = CommunityPostType::tryFrom($request->request->getString('type'));
                if (!$type instanceof CommunityPostType) {
                    throw new InvalidArgumentException('Невалиден тип публикация.');
                }

                $post = $this->postService->create(
                    $user,
                    $type,
                    $request->request->getString('title'),
                    $request->request->getString('body'),
                    new DateTimeImmutable('now', new DateTimeZone(self::SOFIA)),
                    $this->parseOptionalLocalDateTime($request->request->getString('starts_at')),
                    $this->parseOptionalLocalDateTime($request->request->getString('ends_at')),
                    $this->parsePollOptions($request->request->getString('poll_options')),
                );

                $this->addFlash('success', 'Публикацията е добавена в общността.');

                return $this->redirectToRoute('app_community_show', ['id' => $post->getId()]);
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('community/new.html.twig', [
            'types' => CommunityPostType::cases(),
            'error' => $error,
        ], new Response(status: $status));
    }

    #[Route('/community/{id}', name: 'app_community_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $user = $this->requireCommunityUser();
        $post = $this->publishedPost($id);

        /** @var list<CommunityComment> $comments */
        $comments = $this->entityManager->getRepository(CommunityComment::class)->findBy(['post' => $post, 'status' => CommunityContentStatus::PUBLISHED], ['createdAt' => 'ASC']);
        /** @var list<CommunityReaction> $reactions */
        $reactions = $this->entityManager->getRepository(CommunityReaction::class)->findBy(['post' => $post]);
        /** @var list<CommunityPollOption> $options */
        $options = $this->entityManager->getRepository(CommunityPollOption::class)->findBy(['poll' => $post], ['position' => 'ASC']);
        /** @var list<CommunityPollVote> $votes */
        $votes = $this->entityManager->getRepository(CommunityPollVote::class)->findBy(['poll' => $post]);

        $reactionCounts = array_fill_keys(array_map(static fn (CommunityReactionType $type): string => $type->value, CommunityReactionType::cases()), 0);
        $userReaction = null;
        foreach ($reactions as $reaction) {
            ++$reactionCounts[$reaction->getType()->value];
            if ($reaction->getUser() === $user) {
                $userReaction = $reaction->getType();
            }
        }

        $voteCounts = [];
        $userVoteOptionId = null;
        foreach ($options as $option) {
            $optionId = $option->getId();
            if (null !== $optionId) {
                $voteCounts[$optionId] = 0;
            }
        }
        foreach ($votes as $vote) {
            $optionId = $vote->getOption()->getId();
            if (null !== $optionId) {
                $voteCounts[$optionId] = ($voteCounts[$optionId] ?? 0) + 1;
                if ($vote->getVoter() === $user) {
                    $userVoteOptionId = $optionId;
                }
            }
        }

        return $this->render('community/show.html.twig', [
            'post' => $post,
            'comments' => $comments,
            'reaction_types' => CommunityReactionType::cases(),
            'reaction_counts' => $reactionCounts,
            'user_reaction' => $userReaction,
            'poll_options' => $options,
            'poll_vote_counts' => $voteCounts,
            'user_vote_option_id' => $userVoteOptionId,
        ]);
    }

    #[Route('/community/{id}/comment', name: 'app_community_comment', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function comment(int $id, Request $request): Response
    {
        $user = $this->requireCommunityUser();
        $post = $this->publishedPost($id);
        $this->requireCsrf('community_comment_'.$id, $request);

        try {
            $this->interactionService->addComment($user, $post, $request->request->getString('body'), new DateTimeImmutable('now', new DateTimeZone(self::SOFIA)));
            $this->addFlash('success', 'Коментарът е добавен.');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_community_show', ['id' => $id]);
    }

    #[Route('/community/{id}/reaction', name: 'app_community_reaction', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function reaction(int $id, Request $request): Response
    {
        $user = $this->requireCommunityUser();
        $post = $this->publishedPost($id);
        $this->requireCsrf('community_reaction_'.$id, $request);

        $type = CommunityReactionType::tryFrom($request->request->getString('type'));
        if (!$type instanceof CommunityReactionType) {
            throw $this->createNotFoundException('Unknown reaction type.');
        }

        $this->interactionService->toggleReaction($user, $post, $type, new DateTimeImmutable('now', new DateTimeZone(self::SOFIA)));

        return $this->redirectToRoute('app_community_show', ['id' => $id]);
    }

    #[Route('/community/{id}/poll-vote', name: 'app_community_poll_vote', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function pollVote(int $id, Request $request): Response
    {
        $user = $this->requireCommunityUser();
        $post = $this->publishedPost($id);
        $this->requireCsrf('community_poll_vote_'.$id, $request);

        $option = $this->entityManager->find(CommunityPollOption::class, $request->request->getInt('option_id'));
        if (!$option instanceof CommunityPollOption) {
            throw $this->createNotFoundException('Poll option not found.');
        }

        $this->interactionService->castPollVote($user, $post, $option, new DateTimeImmutable('now', new DateTimeZone(self::SOFIA)));

        return $this->redirectToRoute('app_community_show', ['id' => $id]);
    }

    #[Route('/community/{id}/report', name: 'app_community_report_post', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function reportPost(int $id, Request $request): Response
    {
        $user = $this->requireCommunityUser();
        $post = $this->publishedPost($id);
        $this->requireCsrf('community_report_post_'.$id, $request);

        try {
            $this->moderationService->reportPost($user, $post, $request->request->getString('reason'), new DateTimeImmutable('now', new DateTimeZone(self::SOFIA)));
            $this->addFlash('success', 'Сигналът е изпратен за преглед.');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_community_show', ['id' => $id]);
    }

    #[Route('/community/comment/{id}/report', name: 'app_community_report_comment', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function reportComment(int $id, Request $request): Response
    {
        $user = $this->requireCommunityUser();
        $comment = $this->entityManager->find(CommunityComment::class, $id);
        if (!$comment instanceof CommunityComment || !$comment->isPublished() || !$comment->getPost()->isPublished()) {
            throw $this->createNotFoundException('Community comment not found.');
        }
        $this->requireCsrf('community_report_comment_'.$id, $request);

        try {
            $this->moderationService->reportComment($user, $comment, $request->request->getString('reason'), new DateTimeImmutable('now', new DateTimeZone(self::SOFIA)));
            $this->addFlash('success', 'Сигналът за коментара е изпратен за преглед.');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_community_show', ['id' => $comment->getPost()->getId()]);
    }

    private function requireCommunityUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            throw $this->createAccessDeniedException('Community access is required.');
        }

        return $user;
    }

    private function publishedPost(int $id): CommunityPost
    {
        $post = $this->entityManager->find(CommunityPost::class, $id);
        if (!$post instanceof CommunityPost || !$post->isPublished()) {
            throw $this->createNotFoundException('Community post not found.');
        }

        return $post;
    }

    private function requireCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function parseOptionalLocalDateTime(string $input): ?DateTimeImmutable
    {
        $input = trim($input);
        if ('' === $input) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $input, new DateTimeZone(self::SOFIA));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d\\TH:i') !== $input) {
            throw new InvalidArgumentException('Невалидна дата или час.');
        }

        return $date;
    }

    /** @return list<string> */
    private function parsePollOptions(string $input): array
    {
        if ('' === trim($input)) {
            return [];
        }

        $parts = preg_split('/\\R/u', $input);
        if (false === $parts) {
            throw new InvalidArgumentException('Невалидни опции на анкетата.');
        }

        return array_values(array_filter(array_map(static fn (string $value): string => trim($value), $parts), static fn (string $value): bool => '' !== $value));
    }

    /**
     * @param list<CommunityPost> $posts
     * @return array<int, array{comments:int,reactions:int,poll_options:list<array{option:CommunityPollOption,votes:int}>}>
     */
    private function buildPostStats(array $posts): array
    {
        $stats = [];
        foreach ($posts as $post) {
            $id = $post->getId();
            if (null !== $id) {
                $stats[$id] = ['comments' => 0, 'reactions' => 0, 'poll_options' => []];
            }
        }
        if ([] === $posts) {
            return $stats;
        }

        /** @var list<CommunityComment> $comments */
        $comments = $this->entityManager->getRepository(CommunityComment::class)->findBy(['post' => $posts, 'status' => CommunityContentStatus::PUBLISHED]);
        foreach ($comments as $comment) {
            $postId = $comment->getPost()->getId();
            if (null !== $postId && isset($stats[$postId])) {
                ++$stats[$postId]['comments'];
            }
        }

        /** @var list<CommunityReaction> $reactions */
        $reactions = $this->entityManager->getRepository(CommunityReaction::class)->findBy(['post' => $posts]);
        foreach ($reactions as $reaction) {
            $postId = $reaction->getPost()->getId();
            if (null !== $postId && isset($stats[$postId])) {
                ++$stats[$postId]['reactions'];
            }
        }

        $polls = array_values(array_filter($posts, static fn (CommunityPost $post): bool => CommunityPostType::POLL === $post->getType()));
        if ([] === $polls) {
            return $stats;
        }

        /** @var list<CommunityPollOption> $options */
        $options = $this->entityManager->getRepository(CommunityPollOption::class)->findBy(['poll' => $polls], ['position' => 'ASC']);
        /** @var list<CommunityPollVote> $votes */
        $votes = $this->entityManager->getRepository(CommunityPollVote::class)->findBy(['poll' => $polls]);
        $voteCounts = [];
        foreach ($votes as $vote) {
            $optionId = $vote->getOption()->getId();
            if (null !== $optionId) {
                $voteCounts[$optionId] = ($voteCounts[$optionId] ?? 0) + 1;
            }
        }
        foreach ($options as $option) {
            $postId = $option->getPoll()->getId();
            $optionId = $option->getId();
            if (null !== $postId && null !== $optionId && isset($stats[$postId])) {
                $stats[$postId]['poll_options'][] = ['option' => $option, 'votes' => $voteCounts[$optionId] ?? 0];
            }
        }

        return $stats;
    }
}
