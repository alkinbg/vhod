<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CommunityComment;
use App\Entity\CommunityPollOption;
use App\Entity\CommunityPollVote;
use App\Entity\CommunityPost;
use App\Entity\CommunityReaction;
use App\Entity\CommunityReport;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\CommunityContentStatus;
use App\Enum\CommunityPostType;
use App\Enum\CommunityReactionType;
use App\Enum\CommunityReportStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CommunityInteractionEntitiesTest extends TestCase
{
    public function testCommentIsTrimmedAndCanBeHidden(): void
    {
        $comment = CommunityComment::write($this->post(), $this->user(), '  Благодаря!  ', new DateTimeImmutable('2026-09-08 18:00 Europe/Sofia'));
        self::assertSame('Благодаря!', $comment->getBody());
        self::assertSame('UTC', $comment->getCreatedAt()->getTimezone()->getName());
        $comment->hide();
        self::assertSame(CommunityContentStatus::HIDDEN, $comment->getStatus());
        $comment->publishAgain();
        self::assertTrue($comment->isPublished());
    }

    public function testPollOptionRequiresPollPost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CommunityPollOption::create($this->post(), 'Да', 1);
    }

    public function testVoteRejectsOptionFromAnotherPoll(): void
    {
        $pollA = $this->poll('Анкета A');
        $pollB = $this->poll('Анкета B');
        $optionA = CommunityPollOption::create($pollA, 'Да', 1);
        $optionB = CommunityPollOption::create($pollB, 'Не', 1);
        $vote = CommunityPollVote::cast($pollA, $optionA, $this->user(), new DateTimeImmutable());
        $this->expectException(InvalidArgumentException::class);
        $vote->changeOption($optionB, new DateTimeImmutable());
    }

    public function testReactionCanChangeType(): void
    {
        $reaction = CommunityReaction::react($this->post(), $this->user(), CommunityReactionType::LIKE, new DateTimeImmutable());
        $reaction->changeType(CommunityReactionType::THANKS, new DateTimeImmutable('+1 minute'));
        self::assertSame(CommunityReactionType::THANKS, $reaction->getType());
    }

    public function testReportTargetsExactlyOneContentTypeAndResolves(): void
    {
        $reporter = $this->user();
        $resolver = $this->user('manager@example.com');
        $report = CommunityReport::forPost($reporter, $this->post(), '  Неподходящо съдържание  ', new DateTimeImmutable());
        self::assertSame('Неподходящо съдържание', $report->getReason());
        self::assertSame(CommunityReportStatus::OPEN, $report->getStatus());
        self::assertSame(1, $report->getOpenMarker());
        $report->resolve($resolver, new DateTimeImmutable('+1 hour'));
        self::assertSame(CommunityReportStatus::RESOLVED, $report->getStatus());
        self::assertNull($report->getOpenMarker());
        self::assertSame($resolver, $report->getResolver());
    }

    public function testBlankReportReasonIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CommunityReport::forPost($this->user(), $this->post(), '   ', new DateTimeImmutable());
    }

    private function post(): CommunityPost
    {
        return CommunityPost::publish($this->user(), CommunityPostType::POST, 'Съобщение', 'Текст', new DateTimeImmutable());
    }

    private function poll(string $title): CommunityPost
    {
        return CommunityPost::publish($this->user(), CommunityPostType::POLL, $title, 'Изберете отговор.', new DateTimeImmutable());
    }

    private function user(string $email = 'ivan@example.com'): User
    {
        $person = new Person('Иван', 'Иванов', email: $email);
        return new User($person, $email, 'hash');
    }
}
