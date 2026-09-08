<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CommunityPollOption;
use App\Entity\CommunityPost;
use App\Entity\User;
use App\Enum\CommunityPostType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final readonly class CommunityPostService
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    /** @param list<string> $pollOptions */
    public function create(User $author, CommunityPostType $type, string $title, string $body, DateTimeImmutable $createdAt, ?DateTimeImmutable $startsAt = null, ?DateTimeImmutable $endsAt = null, array $pollOptions = []): CommunityPost
    {
        $options = self::normalizePollOptions($pollOptions);
        if (CommunityPostType::POLL === $type) {
            if (count($options) < 2) {
                throw new InvalidArgumentException('Informal poll requires at least two unique options.');
            }
        } elseif ([] !== $options) {
            throw new InvalidArgumentException('Poll options may be supplied only for poll posts.');
        }

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($author, $type, $title, $body, $createdAt, $startsAt, $endsAt, $options): CommunityPost {
            $post = CommunityPost::publish($author, $type, $title, $body, $createdAt, $startsAt, $endsAt);
            $entityManager->persist($post);
            foreach ($options as $index => $label) {
                $entityManager->persist(CommunityPollOption::create($post, $label, $index + 1));
            }

            return $post;
        });
    }

    /**
     * @param list<string> $options
     *
     * @return list<string>
     */
    private static function normalizePollOptions(array $options): array
    {
        $normalized = [];
        $seen = [];
        foreach ($options as $option) {
            $option = trim($option);
            if ('' === $option) {
                continue;
            }
            $key = mb_strtolower($option);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Informal poll options must be unique.');
            }
            $seen[$key] = true;
            $normalized[] = $option;
        }

        return $normalized;
    }
}
