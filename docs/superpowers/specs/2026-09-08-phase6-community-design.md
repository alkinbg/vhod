# Phase 6 Community Design

## Goal

Implement roadmap slice 6 as a private, authenticated neighbours-only community area on top of the existing `User`/`Person` identity and Symfony security model.

The community must support everyday neighbour communication without being confused with official condominium announcements, General Assembly resolutions, or formal votes.

## Scope

Phase 6 includes:

- neighbours-only community feed;
- posts;
- comments;
- reactions;
- informal polls;
- events;
- ideas and proposals;
- mutual-help requests;
- internal give/lend/borrow/find board;
- user reporting;
- manager/admin moderation;
- explicit visual and semantic separation from official notices and formal voting.

Phase 6 does not include:

- chat or private messaging;
- WebSocket/Mercure real-time delivery;
- file uploads, photos, or attachments;
- resident notifications;
- official announcements;
- formal General Assembly voting;
- nested comment threads;
- public or anonymous community access.

Attachments/photos belong to later roadmap slices. Official announcements belong to Phase 8. Formal voting belongs exclusively to Phase 9.

## Architectural approach

Use one primary content aggregate, `CommunityPost`, with a typed purpose instead of creating separate near-duplicate entities and controllers for events, ideas, help requests, board listings, and ordinary posts.

Supporting entities are deliberately small and focused:

- `CommunityPost` — primary feed item;
- `CommunityComment` — flat comments on a post;
- `CommunityReaction` — one reaction per user/post;
- `CommunityPollOption` — poll choices owned by a poll post;
- `CommunityPollVote` — one vote per user/poll;
- `CommunityReport` — moderation report for one post or one comment.

There is no separate community profile/member model. Authors and voters reference the existing `User`, whose `Person` provides the display name.

## Post model

`CommunityPost` contains:

- author (`User`);
- type (`CommunityPostType`);
- title;
- body;
- moderation status;
- created-at timestamp;
- optional event start/end timestamps.

Supported `CommunityPostType` values:

- `POST` — ordinary neighbour post;
- `POLL` — informal poll;
- `EVENT` — community event;
- `IDEA` — idea/proposal for discussion;
- `HELP` — mutual-help request;
- `GIVE` — item offered free;
- `LEND` — item offered for temporary lending;
- `BORROW` — request to borrow an item;
- `FIND` — lost/found or item/person-information request.

The last four types form the internal neighbour board but remain feed posts rather than a separate marketplace subsystem.

### Post invariants

- title and body are trimmed and required;
- event posts require `startsAt`;
- `endsAt`, when present, must be after `startsAt`;
- non-event posts do not store event timestamps;
- timestamps are persisted in UTC and rendered in `Europe/Sofia`;
- new posts start as `PUBLISHED`;
- no physical post deletion is exposed in Phase 6.

## Moderation status

`CommunityContentStatus` has:

- `PUBLISHED`;
- `HIDDEN`.

Moderation hides content instead of deleting it. This preserves context for reports and avoids destructive moderation operations.

Hidden posts are excluded from the ordinary feed and direct resident view. Managers/admins may still view them in moderation context.

Hidden comments remain stored but are not rendered to ordinary residents.

## Comments

`CommunityComment` contains:

- post;
- author;
- body;
- moderation status;
- created-at timestamp.

Comments are flat. Replies-to-replies and arbitrary nesting are intentionally excluded.

A comment cannot be added to a hidden post through the normal resident workflow.

## Reactions

`CommunityReactionType` initially supports:

- `LIKE`;
- `SUPPORT`;
- `THANKS`.

`CommunityReaction` references one post and one user. A database unique constraint on `(post_id, user_id)` guarantees at most one active reaction per user/post.

Posting a new reaction for the same user/post replaces the existing reaction type; posting the same reaction again removes it. The operation is implemented transactionally so duplicate rows cannot be created by concurrent requests.

Reactions are available only on published posts.

## Informal polls

Polls are explicitly non-binding community discussions.

A `POLL` post owns two or more `CommunityPollOption` rows. Each option contains its display label and stable ordering position.

`CommunityPollVote` references:

- poll post;
- option;
- voter (`User`);
- voted-at timestamp.

Database constraints enforce one vote per user per poll. The application service verifies that the selected option belongs to the same poll.

Phase 6 polls are single-choice. A resident may change their vote; this updates the existing vote transactionally rather than inserting a second vote.

The poll UI must always show a visible label equivalent to: **„Неформална анкета — няма сила на решение на Общото събрание.“**

No Phase 6 entity or route is reused for Phase 9 formal voting.

## Events

Events use `CommunityPostType::EVENT` plus `startsAt` and optional `endsAt` on `CommunityPost`.

This keeps Phase 6 simple while still allowing:

- event creation;
- chronological event metadata in the feed;
- future event filtering later without schema redesign.

No RSVP/attendance subsystem is included in Phase 6.

## Reports and moderation

`CommunityReport` targets exactly one of:

- a `CommunityPost`; or
- a `CommunityComment`.

It contains:

- reporter (`User`);
- target post or target comment;
- reason text;
- status;
- created-at timestamp;
- optional resolved-at timestamp;
- optional resolver (`User`).

`CommunityReportStatus` has:

- `OPEN`;
- `RESOLVED`.

Invariants:

- exactly one target must be set;
- reason is required and trimmed;
- one user may not create duplicate open reports for the same target;
- resolving a report requires manager/admin authority;
- resolving a report does not automatically hide content; moderation and report resolution are explicit actions.

This separation prevents accidental content removal merely because a report exists.

## Access model

The existing global security rule already requires `ROLE_USER` for all non-login routes. Community routes additionally enforce active authenticated `App\Entity\User` access at controller/service boundaries.

Residents and all management roles may:

- view published community posts;
- create posts;
- comment;
- react;
- vote in informal polls;
- report posts/comments.

`ROLE_MANAGER` and `ROLE_ADMIN` may additionally:

- open the moderation queue;
- view open/resolved reports;
- hide/unhide posts;
- hide/unhide comments;
- resolve reports.

`ROLE_CASHIER` and `ROLE_CONTROLLER` receive no special moderation authority merely because of those roles.

## Routes

Resident routes:

- `GET /community` — feed;
- `GET|POST /community/new` — create post/event/idea/help/board item;
- `GET /community/{id}` — post detail;
- `POST /community/{id}/comment` — add comment;
- `POST /community/{id}/reaction` — toggle/change reaction;
- `POST /community/{id}/poll-vote` — cast/change poll vote;
- `POST /community/{id}/report` — report post;
- `POST /community/comment/{id}/report` — report comment.

Moderation routes:

- `GET /management/community/moderation` — moderation/report queue;
- `POST /management/community/post/{id}/visibility` — hide/unhide post;
- `POST /management/community/comment/{id}/visibility` — hide/unhide comment;
- `POST /management/community/report/{id}/resolve` — resolve report.

All write routes require CSRF tokens.

## Controllers and services

Controllers stay thin and are responsible for:

- authenticated-user extraction;
- request/form-field extraction;
- CSRF validation;
- role checks;
- calling application services;
- translating expected validation failures to user-facing form errors/HTTP responses;
- redirects/rendering.

Business mutations live in focused services rather than controller methods:

- `CommunityPostService` — create posts and poll options;
- `CommunityInteractionService` — comments, reactions, poll votes;
- `CommunityModerationService` — reports, visibility changes, report resolution.

These services use Doctrine transactions where uniqueness/concurrency matters, especially reactions, poll votes, and report creation.

No additional repository abstraction layer or DTO layer is introduced unless implementation proves a concrete need.

## Feed behaviour

The community feed:

- contains only `PUBLISHED` posts;
- sorts newest first by creation time;
- supports an optional type filter;
- displays author `Person::getDisplayName()`;
- shows event date metadata for events;
- shows reaction counts and comment count;
- shows poll result counts only for poll posts;
- clearly labels the section as community/unofficial content.

The initial phase may use a bounded page size without implementing a complex pagination library. Database queries must avoid loading hidden posts into the normal resident feed.

## Validation and security

Server-side validation is authoritative.

Minimum rules:

- title: non-empty, bounded length;
- body/comment/report reason: non-empty, bounded length;
- poll: at least two unique non-empty options;
- event start/end consistency;
- enum input resolved through `tryFrom()` or equivalent safe validation;
- referenced post/comment/option must exist;
- hidden posts reject ordinary interaction;
- users cannot submit moderation actions without manager/admin authority;
- every mutation requires CSRF;
- templates escape user content normally through Twig; no raw HTML authoring is introduced.

## Database rules

Community history is private application data, not an immutable financial ledger, but destructive cascades from identity records are still avoided.

- author/reporter/voter relationships to `User` use `ON DELETE RESTRICT`;
- post/comment/report history is retained through status transitions rather than hard deletion;
- unique DB constraints protect reaction, vote, and duplicate-open-report invariants;
- indexes cover feed ordering/status and moderation queue status/created time;
- migration must be safe for the existing production schema and must not rewrite existing large financial tables.

## UI

Add `Общност` to the authenticated main navigation.

Visual requirements:

- community pages use the existing application shell;
- a visible community/unofficial badge or notice appears on feed/detail pages;
- poll cards explicitly state that voting is informal;
- moderation actions are not mixed into the normal resident controls;
- post types have clear Bulgarian labels;
- forms remain simple HTML/Twig forms using the project's existing manual request/CSRF style.

The UI is intentionally functional before decorative. No SPA framework or JavaScript dependency is required for the first Phase 6 slice.

## Testing strategy

### Domain/service tests

Cover real business invariants and concurrency-sensitive behaviour:

- post/event validation;
- poll option validation;
- reaction toggle/change semantics;
- one vote per user/poll with vote change;
- option/poll consistency;
- duplicate open report prevention;
- moderation state transitions and authorization assumptions at service boundaries where applicable.

### Functional controller smoke tests

Use `WebTestCase`, not mock-heavy controller unit tests.

Critical scenarios:

- anonymous user cannot enter community;
- authenticated resident can view feed;
- resident can create a normal post;
- resident can add a comment;
- resident can react;
- resident can create an informal poll and vote;
- duplicate poll submission changes the vote instead of creating another row;
- resident can report content;
- resident cannot open moderation or hide content;
- manager/admin can open moderation queue, hide/unhide content, and resolve reports;
- hidden content is absent from resident feed/detail interaction.

Controller tests exist to verify routing, security, CSRF and HTTP behaviour. Business logic is tested at service/entity level.

## Migration and verification gates

Before Phase 6 is mergeable:

- `composer validate --strict`;
- container lint/validation;
- Doctrine mapping validation;
- MariaDB migration up/down/up and schema sync;
- focused community PHPUnit tests;
- full `vendor/bin/phpunit`;
- `vendor/bin/phpstan analyse --no-progress`;
- PR diff review for missing controller/templates/routes and unintended scope creep.

A green test suite is not considered sufficient if any production layer described by the spec is physically missing. The final review must verify the complete vertical slice: domain/schema -> services -> controllers/routes -> Twig UI -> functional tests.
