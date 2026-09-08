# Phase 6 Community Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the complete private neighbours-only community vertical slice: feed, posts, comments, reactions, informal polls, events/ideas/help/board posts, reporting and manager/admin moderation.

**Architecture:** Use one typed `CommunityPost` aggregate for all feed content and small supporting entities for comments, reactions, poll options/votes and reports. Keep controllers thin; put mutations in three focused Doctrine-backed services. Serialize concurrency-sensitive reaction/vote/report mutations with `PESSIMISTIC_WRITE` on the target post/comment and back them with database unique constraints.

**Tech Stack:** PHP 8.4, Symfony 8.1, Doctrine ORM 3.7, MariaDB 10.11, Twig, AssetMapper, PHPUnit 12, PHPStan 2.

**Spec:** `docs/superpowers/specs/2026-09-08-phase6-community-design.md`

## Global Constraints

- community is private and authenticated; no public/anonymous content;
- existing `User`/`Person` identity is reused; no community profile/member abstraction;
- community polls are explicitly informal and must never share entities/routes with Phase 9 formal voting;
- no chat, Mercure/WebSocket, file upload, photo attachment, notification or official-announcement subsystem in Phase 6;
- no hard-delete workflow for community content; moderation uses `PUBLISHED`/`HIDDEN`;
- all user/content foreign keys use `ON DELETE RESTRICT`;
- every write route requires CSRF;
- only `ROLE_MANAGER` and `ROLE_ADMIN` moderate; cashier/controller roles get no moderation privilege;
- all date-times persist as UTC and display in `Europe/Sofia`;
- templates escape user content; no raw HTML authoring;
- no repository/DTO layer unless implementation demonstrates a concrete need;
- the merge gate verifies the physical vertical slice, not only a green suite.

---

### Task 1: Community post domain

**Files:**
- Create: `src/Enum/CommunityPostType.php`
- Create: `src/Enum/CommunityContentStatus.php`
- Create: `src/Entity/CommunityPost.php`
- Test: `tests/Entity/CommunityPostTest.php`

**Interfaces:**
- `CommunityPostType::labelBg(): string`
- `CommunityPost::publish(User $author, CommunityPostType $type, string $title, string $body, DateTimeImmutable $createdAt, ?DateTimeImmutable $startsAt = null, ?DateTimeImmutable $endsAt = null): self`
- `CommunityPost::hide(): void`
- `CommunityPost::publishAgain(): void`
- standard getters for id/author/type/title/body/status/createdAt/startsAt/endsAt

- [ ] **Step 1: Write RED entity tests for normal posts and event invariants**

```php
public function testPublishesTrimmedPostInUtc(): void
{
    $post = CommunityPost::publish(
        $this->user(),
        CommunityPostType::POST,
        '  Асансьорът  ',
        '  Работи отново.  ',
        new DateTimeImmutable('2026-09-08 18:00:00 Europe/Sofia'),
    );

    self::assertSame('Асансьорът', $post->getTitle());
    self::assertSame('Работи отново.', $post->getBody());
    self::assertSame(CommunityContentStatus::PUBLISHED, $post->getStatus());
    self::assertSame('UTC', $post->getCreatedAt()->getTimezone()->getName());
}

public function testEventRequiresStartAndEndAfterStart(): void
{
    $this->expectException(InvalidArgumentException::class);
    CommunityPost::publish($this->user(), CommunityPostType::EVENT, 'Среща', 'В двора', new DateTimeImmutable(), null);
}
```

- [ ] **Step 2: Run focused test and confirm RED**

Run: `vendor/bin/phpunit tests/Entity/CommunityPostTest.php`

Expected: FAIL because enums/entity do not exist.

- [ ] **Step 3: Implement enums and entity with strict invariants**

Use backed string enum values:

```php
POST='post'; POLL='poll'; EVENT='event'; IDEA='idea'; HELP='help'; GIVE='give'; LEND='lend'; BORROW='borrow'; FIND='find';
```

`CommunityContentStatus`: `PUBLISHED='published'`, `HIDDEN='hidden'`.

Entity limits: title 160 chars, body non-empty and maximum 10,000 characters. Reject event dates on non-event posts instead of silently discarding them.

- [ ] **Step 4: Run focused test and make it GREEN**

Run: `vendor/bin/phpunit tests/Entity/CommunityPostTest.php`

- [ ] **Step 5: Commit task**

Commit message: `Add Phase 6 community post domain`.

---

### Task 2: Interaction/report entities and schema

**Files:**
- Create: `src/Enum/CommunityReactionType.php`
- Create: `src/Enum/CommunityReportStatus.php`
- Create: `src/Entity/CommunityComment.php`
- Create: `src/Entity/CommunityReaction.php`
- Create: `src/Entity/CommunityPollOption.php`
- Create: `src/Entity/CommunityPollVote.php`
- Create: `src/Entity/CommunityReport.php`
- Create: `migrations/Version20260908150000.php`
- Test: `tests/Entity/CommunityInteractionEntitiesTest.php`
- Test: `tests/Doctrine/CommunitySchemaTest.php`

**Interfaces:**
- `CommunityReactionType`: `LIKE`, `SUPPORT`, `THANKS`, plus `labelBg(): string`
- `CommunityComment::write(CommunityPost $post, User $author, string $body, DateTimeImmutable $createdAt): self`
- `CommunityReaction::react(CommunityPost $post, User $user, CommunityReactionType $type, DateTimeImmutable $reactedAt): self`
- `CommunityReaction::changeType(CommunityReactionType $type, DateTimeImmutable $reactedAt): void`
- `CommunityPollOption::create(CommunityPost $poll, string $label, int $position): self`
- `CommunityPollVote::cast(CommunityPost $poll, CommunityPollOption $option, User $voter, DateTimeImmutable $votedAt): self`
- `CommunityPollVote::changeOption(CommunityPollOption $option, DateTimeImmutable $votedAt): void`
- `CommunityReport::forPost(User $reporter, CommunityPost $post, string $reason, DateTimeImmutable $createdAt): self`
- `CommunityReport::forComment(User $reporter, CommunityComment $comment, string $reason, DateTimeImmutable $createdAt): self`
- `CommunityReport::resolve(User $resolver, DateTimeImmutable $resolvedAt): void`

- [ ] **Step 1: Write RED domain tests**

Cover blank comments/reasons, poll-only options, unique positive option position, option/poll consistency, report exact-one-target, status transitions, UTC timestamps and hide/unhide comment behaviour.

Example:

```php
public function testPollVoteRejectsOptionFromAnotherPoll(): void
{
    $vote = CommunityPollVote::cast($pollA, $optionA, $user, new DateTimeImmutable());

    $this->expectException(InvalidArgumentException::class);
    $vote->changeOption($optionBFromPollB, new DateTimeImmutable());
}
```

- [ ] **Step 2: Implement supporting enums/entities minimally**

Entity rules:

- comments max 5,000 chars;
- report reason max 2,000 chars;
- poll option label max 255 chars;
- options require a `POLL` post;
- votes require a `POLL` post and an option belonging to that poll;
- all identity/post/comment associations use `RESTRICT`.

`CommunityReport` stores a nullable `openKey` (`post:<id>` or `comment:<id>`) only while OPEN; `resolve()` sets it to null. A unique index `(reporter_id, open_key)` provides a database guarantee against duplicate open reports while allowing later re-reporting after resolution.

- [ ] **Step 3: Write the migration explicitly**

Create tables:

- `community_post` with indexes `(status, created_at)` and `(type, status, created_at)`;
- `community_comment` with `(post_id, status, created_at)`;
- `community_reaction` with unique `(post_id, user_id)`;
- `community_poll_option` with unique `(post_id, position)`;
- `community_poll_vote` with unique `(post_id, voter_id)`;
- `community_report` with indexes `(status, created_at)` and unique `(reporter_id, open_key)`.

Every FK uses `ON DELETE RESTRICT`. Down migration drops FKs before tables in dependency order.

- [ ] **Step 4: Write schema metadata tests**

Assert table/column/index/unique names and that all community FKs use `RESTRICT`; include checks for reaction/vote/report uniqueness.

- [ ] **Step 5: Verify focused tests and mapping**

Run:

```bash
vendor/bin/phpunit tests/Entity/CommunityInteractionEntitiesTest.php tests/Doctrine/CommunitySchemaTest.php
php bin/console doctrine:schema:validate
```

- [ ] **Step 6: Commit task**

Commit message: `Add community interaction persistence`.

---

### Task 3: Community post creation service

**Files:**
- Create: `src/Service/CommunityPostService.php`
- Test: `tests/Service/CommunityPostServiceTest.php`

**Interfaces:**

```php
/** @param list<string> $pollOptions */
public function create(
    User $author,
    CommunityPostType $type,
    string $title,
    string $body,
    DateTimeImmutable $createdAt,
    ?DateTimeImmutable $startsAt = null,
    ?DateTimeImmutable $endsAt = null,
    array $pollOptions = [],
): CommunityPost;
```

- [ ] **Step 1: Write RED integration tests**

Cover ordinary post persistence, event persistence, poll with two+ unique trimmed options, rejection of one-option/duplicate-option poll, and rejection of poll options for a non-poll post.

```php
$post = $service->create($user, CommunityPostType::POLL, 'Домофон', 'Да го сменим ли?', $now, pollOptions: ['Да', 'Не']);
self::assertCount(2, $em->getRepository(CommunityPollOption::class)->findBy(['poll' => $post]));
```

- [ ] **Step 2: Implement service in one Doctrine transaction**

Normalize poll labels in the service, reject case-insensitive duplicates with `mb_strtolower`, create positions starting from `1`, persist post/options, flush once.

- [ ] **Step 3: Run focused service test**

Run: `vendor/bin/phpunit tests/Service/CommunityPostServiceTest.php`

- [ ] **Step 4: Commit task**

Commit message: `Add community post creation service`.

---

### Task 4: Comments, reactions and informal voting service

**Files:**
- Create: `src/Service/CommunityInteractionService.php`
- Test: `tests/Service/CommunityInteractionServiceTest.php`

**Interfaces:**

```php
public function addComment(User $author, CommunityPost $post, string $body, DateTimeImmutable $createdAt): CommunityComment;
public function toggleReaction(User $user, CommunityPost $post, CommunityReactionType $type, DateTimeImmutable $reactedAt): ?CommunityReaction;
public function castPollVote(User $user, CommunityPost $poll, CommunityPollOption $option, DateTimeImmutable $votedAt): CommunityPollVote;
```

- [ ] **Step 1: Write RED tests for interaction semantics**

Cover:

- comment on published post succeeds;
- comment/reaction/vote on hidden post fails;
- first reaction inserts;
- different reaction updates the same row;
- same reaction toggles the row off;
- first vote inserts;
- second option changes the existing vote and row count remains one;
- option from another poll fails.

- [ ] **Step 2: Implement transactional locking**

For `toggleReaction()` and `castPollVote()`:

```php
return $this->entityManager->wrapInTransaction(function () use (...) {
    $this->entityManager->lock($post, LockMode::PESSIMISTIC_WRITE);
    // re-check PUBLISHED status after the lock
    // load existing unique row, mutate/remove/create, flush
});
```

For `addComment()`, reject hidden content server-side before persist/flush.

- [ ] **Step 3: Run focused tests**

Run: `vendor/bin/phpunit tests/Service/CommunityInteractionServiceTest.php`

- [ ] **Step 4: Commit task**

Commit message: `Add community interactions`.

---

### Task 5: Reporting and moderation service

**Files:**
- Create: `src/Service/CommunityModerationService.php`
- Test: `tests/Service/CommunityModerationServiceTest.php`

**Interfaces:**

```php
public function reportPost(User $reporter, CommunityPost $post, string $reason, DateTimeImmutable $createdAt): CommunityReport;
public function reportComment(User $reporter, CommunityComment $comment, string $reason, DateTimeImmutable $createdAt): CommunityReport;
public function setPostHidden(User $moderator, CommunityPost $post, bool $hidden): void;
public function setCommentHidden(User $moderator, CommunityComment $comment, bool $hidden): void;
public function resolveReport(User $moderator, CommunityReport $report, DateTimeImmutable $resolvedAt): void;
```

- [ ] **Step 1: Write RED tests**

Cover duplicate-open-report rejection, re-report after resolution, resident moderation denial, cashier/controller denial, manager/admin success, hide/unhide transitions and report resolution metadata.

- [ ] **Step 2: Implement moderator authority in the service**

Use the existing `User::getRoles()` result and allow only `ROLE_MANAGER` or `ROLE_ADMIN`. Do not grant authority from `ROLE_CASHIER` or `ROLE_CONTROLLER`.

- [ ] **Step 3: Serialize report creation**

Inside `wrapInTransaction()` pessimistically lock the reported post/comment, query by reporter + `openKey`, reject an existing OPEN report, persist and flush.

- [ ] **Step 4: Run focused tests**

Run: `vendor/bin/phpunit tests/Service/CommunityModerationServiceTest.php`

- [ ] **Step 5: Commit task**

Commit message: `Add community reporting and moderation`.

---

### Task 6: Resident and moderation HTTP flows

**Files:**
- Create: `src/Controller/CommunityController.php`
- Create: `src/Controller/CommunityModerationController.php`
- Test: `tests/Controller/CommunityControllerTest.php`
- Test: `tests/Controller/CommunityModerationControllerTest.php`

**Interfaces / routes:**

Resident:

- `GET /community` -> `app_community_index`
- `GET|POST /community/new` -> `app_community_new`
- `GET /community/{id}` -> `app_community_show`
- `POST /community/{id}/comment` -> `app_community_comment`
- `POST /community/{id}/reaction` -> `app_community_reaction`
- `POST /community/{id}/poll-vote` -> `app_community_poll_vote`
- `POST /community/{id}/report` -> `app_community_report_post`
- `POST /community/comment/{id}/report` -> `app_community_report_comment`

Moderation:

- `GET /management/community/moderation` -> `app_community_moderation`
- `POST /management/community/post/{id}/visibility` -> `app_community_moderation_post_visibility`
- `POST /management/community/comment/{id}/visibility` -> `app_community_moderation_comment_visibility`
- `POST /management/community/report/{id}/resolve` -> `app_community_moderation_report_resolve`

CSRF ids:

- `community_post_create`
- `community_comment_{id}`
- `community_reaction_{id}`
- `community_poll_vote_{id}`
- `community_report_post_{id}`
- `community_report_comment_{id}`
- `community_visibility_post_{id}`
- `community_visibility_comment_{id}`
- `community_report_resolve_{id}`

- [ ] **Step 1: Write functional smoke tests before controllers**

Tests must verify real routing/security/CSRF rather than mock calls. Critical assertions:

```php
$client->request('GET', '/community');
self::assertResponseRedirects('/login');
```

Authenticated resident creates a post through the rendered form and receives redirect to its detail page. Resident sees 403 for moderation. Invalid CSRF on every mutation family causes 403 and no mutation.

- [ ] **Step 2: Implement `CommunityController` thinly**

Use safe enum `tryFrom()` parsing. Parse `poll_options` textarea as one option per non-empty line. Parse Sofia-local `datetime-local` event input explicitly with `DateTimeZone('Europe/Sofia')`.

Feed query requirements: only `PUBLISHED`, optional valid `type` filter, newest-first, maximum 50 posts. Hidden direct post access returns 404 to normal residents.

- [ ] **Step 3: Implement `CommunityModerationController` thinly**

Enforce manager/admin before any moderation data is exposed. Queue defaults to OPEN reports and accepts only known report status filter values.

- [ ] **Step 4: Run controller tests**

Run:

```bash
vendor/bin/phpunit tests/Controller/CommunityControllerTest.php tests/Controller/CommunityModerationControllerTest.php
```

- [ ] **Step 5: Commit task**

Commit message: `Add community HTTP workflows`.

---

### Task 7: Twig community UI and navigation

**Files:**
- Create: `templates/community/index.html.twig`
- Create: `templates/community/new.html.twig`
- Create: `templates/community/show.html.twig`
- Create: `templates/community/moderation.html.twig`
- Modify: `templates/base.html.twig`
- Modify: `assets/styles/app.css`
- Extend tests: `tests/Controller/CommunityControllerTest.php`
- Extend tests: `tests/Controller/CommunityModerationControllerTest.php`

- [ ] **Step 1: Add RED rendering assertions**

Verify:

- feed heading `Общност`;
- visible text `Неофициално съдържание`;
- Bulgarian post-type labels;
- poll notice `Неформална анкета — няма сила на решение на Общото събрание.`;
- event time output;
- moderation heading and report reason;
- `Общност` authenticated nav link.

- [ ] **Step 2: Build feed/new/detail templates**

Keep plain Twig/manual forms. Feed cards show author display name, type, created time, event metadata, comment count and reaction counts. Poll detail renders options/result counts and the informal-vote notice. Do not use `|raw` for user content.

- [ ] **Step 3: Build moderation template**

Show open/resolved reports with target author/content context. Hide/unhide and resolve are POST forms with exact CSRF ids. Do not place moderator controls in resident templates.

- [ ] **Step 4: Update navigation and restrained CSS**

Add the `Общност` navigation item for authenticated users. Add only community card/badge/form/report styling needed for readability and print-safe existing pages; do not redesign the application shell.

- [ ] **Step 5: Run functional tests again**

Run:

```bash
vendor/bin/phpunit tests/Controller/CommunityControllerTest.php tests/Controller/CommunityModerationControllerTest.php
```

- [ ] **Step 6: Commit task**

Commit message: `Add community user interface`.

---

### Task 8: Phase 6 verification and merge gate

**Files:**
- Review all Phase 6 changes against spec.

- [ ] **Step 1: Validate metadata/container**

```bash
composer validate --strict
php bin/console lint:container
```

- [ ] **Step 2: Validate Doctrine and MariaDB migrations**

Run Doctrine mapping validation and CI-equivalent MariaDB migrate -> schema validate -> rollback -> migrate -> schema validate sequence.

- [ ] **Step 3: Run focused and full test suites**

```bash
vendor/bin/phpunit tests/Entity/CommunityPostTest.php tests/Entity/CommunityInteractionEntitiesTest.php tests/Service/CommunityPostServiceTest.php tests/Service/CommunityInteractionServiceTest.php tests/Service/CommunityModerationServiceTest.php tests/Controller/CommunityControllerTest.php tests/Controller/CommunityModerationControllerTest.php
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
```

- [ ] **Step 4: Inspect the production vertical slice physically**

Confirm the PR contains all required categories:

- enums/entities;
- migration;
- three application services;
- resident controller/routes;
- moderation controller/routes;
- four Twig templates;
- navigation/style changes;
- domain/service tests;
- functional WebTestCase tests.

A missing production layer blocks merge even if tests happen to be green.

- [ ] **Step 5: Security/concurrency diff review**

Verify no community FK uses `CASCADE` or `SET NULL`, every write route validates CSRF, moderator routes cannot be accessed by resident/cashier/controller, user content is not rendered raw, and reaction/vote/report operations retain both pessimistic locking and DB uniqueness.

- [ ] **Step 6: Open PR and merge only exact green head SHA**

PR title: `Community: complete roadmap phase 6`.

Merge only after CI succeeds for the exact head SHA and the changed-file list matches the planned vertical slice.
