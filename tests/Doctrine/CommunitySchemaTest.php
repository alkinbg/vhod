<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\CommunityComment;
use App\Entity\CommunityPollOption;
use App\Entity\CommunityPollVote;
use App\Entity\CommunityPost;
use App\Entity\CommunityReaction;
use App\Entity\CommunityReport;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommunitySchemaTest extends KernelTestCase
{
    public function testAllCommunityAssociationsRestrictHardDeletion(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $associations = [
            [CommunityPost::class, 'author'], [CommunityComment::class, 'post'], [CommunityComment::class, 'author'],
            [CommunityReaction::class, 'post'], [CommunityReaction::class, 'user'], [CommunityPollOption::class, 'poll'],
            [CommunityPollVote::class, 'poll'], [CommunityPollVote::class, 'option'], [CommunityPollVote::class, 'voter'],
            [CommunityReport::class, 'reporter'], [CommunityReport::class, 'post'], [CommunityReport::class, 'comment'], [CommunityReport::class, 'resolver'],
        ];
        foreach ($associations as [$class, $field]) {
            $mapping = $entityManager->getClassMetadata($class)->getAssociationMapping($field);
            self::assertInstanceOf(ToOneOwningSideMapping::class, $mapping);
            self::assertSame('RESTRICT', $mapping->joinColumns[0]->onDelete, $class.'::'.$field);
        }
    }

    public function testConcurrencySensitiveUniqueConstraintsExist(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertUnique($entityManager, CommunityReaction::class, ['post_id', 'user_id']);
        self::assertUnique($entityManager, CommunityPollVote::class, ['post_id', 'voter_id']);
        self::assertUnique($entityManager, CommunityPollOption::class, ['post_id', 'position']);
        self::assertUnique($entityManager, CommunityReport::class, ['reporter_id', 'post_id', 'open_marker']);
        self::assertUnique($entityManager, CommunityReport::class, ['reporter_id', 'comment_id', 'open_marker']);
    }

    /** @param class-string $class */
    private static function assertUnique(EntityManagerInterface $entityManager, string $class, array $columns): void
    {
        $table = $entityManager->getClassMetadata($class)->table;
        self::assertContains(['columns' => $columns], array_values($table['uniqueConstraints'] ?? []));
    }
}
