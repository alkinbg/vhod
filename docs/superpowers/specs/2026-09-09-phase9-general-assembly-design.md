# Phase 9 — General Assembly design

## Goal

Implement roadmap Phase 9 as a legally aware, authenticated General Assembly workflow for one residential entrance. The slice covers preparation, convening, agenda control, attendance, proxies, quorum, weighted formal voting, resolutions, optional statutory absentee voting, and minutes.

Formal General Assembly voting remains completely separate from Community polls. Vhod records and validates the workflow and its evidence; it does not become a videoconference platform, qualified-signature authority, notary, identity provider, court-proof service system, or generic election product.

The central design rule is historical truth: ownership, represented ideal parts, attendance, legal-rule inputs, votes and results are snapshotted so later changes to `Unit`, `UnitRelation`, users, or legislation cannot silently rewrite an old meeting.

## Verified legal baseline and safety boundary

The design was checked on 2026-09-09 against official Bulgarian sources: the current MRRB page for ЗУЕС, marked as amended through State Gazette issue 49 of 17 June 2025, and National Assembly/State Gazette materials for the amendments governing quorum, online participation and absentee voting.

The implementation baseline currently includes:

- ordinary first-call quorum: at least 51% represented ideal parts;
- delayed call after one hour: at least 26% represented ideal parts;
- special dominant-owner case: when one natural/legal person owns more than 51%, the applicable quorum is at least 75%;
- one proxy may represent at most three owners and/or users where the statutory proxy rule applies;
- online participation may be recorded when an external online-meeting facility is used;
- statutory absentee voting is available only for legally eligible decision categories, through a declaration/evidence workflow and statutory time window;
- minutes have a statutory preparation/notification workflow and deadline.

These values must not be scattered through controllers. The rule applied to each meeting/decision is snapshotted with a stable rule code, exact decimal threshold/denominator semantics, legal-basis text and source/effective-date note.

Vhod is an operational aid, not legal counsel or an evidentiary authority. A Vhod login, click, receipt or uploaded scan must never be described as proof of valid statutory service, identity, qualified electronic signature, notarization or court-proof compliance.

When material ownership or legal-rule data are incomplete or contradictory, automatic legal conclusions fail closed as `REVIEW_REQUIRED`.

## Scope

Phase 9 includes:

- General Assembly lifecycle;
- convening basis and initiator snapshot;
- date/time/place and optional external online-meeting reference;
- ordered agenda and draft resolutions;
- controlled emergency agenda items with explicit reason;
- immutable electorate/ownership snapshot;
- invitation generation using Phase 8 private documents;
- physical posting evidence separate from in-app read state;
- attendance: in person, online, representative/proxy;
- proxy authority and evidence documents;
- represented ideal-parts calculation;
- immutable quorum checks;
- majority-rule snapshots per agenda item;
- `FOR / AGAINST / ABSTAIN` formal votes;
- explicit vote corrections before item close;
- resolution calculation;
- optional statutory absentee-voting follow-up for eligible items;
- meeting close;
- minutes draft, PDF and finalization;
- append-only minutes corrections/addenda;
- resident read-only views;
- manager/controller/admin live workbench;
- no hard-delete workflow.

## Non-goals

No Phase 9 implementation of:

- WebRTC/video hosting;
- cryptographic QES verification or signature issuance;
- notarization;
- public/anonymous meeting URLs;
- secret ballots;
- blockchain/cryptographic voting;
- remote KYC;
- generic parliamentary procedure;
- municipal/state-register integration;
- court-challenge automation;
- automatic legal advice for disputed cases;
- multi-building SaaS abstractions;
- Community poll reuse.

## Core principles

### Formal votes are a separate bounded context

`CommunityPollVote` remains informal neighbour opinion. General Assembly entities, repositories, services, routes and templates are separate. No Community row can influence formal quorum or results.

### Historical snapshots, not live legal state

After convening, calculations use meeting snapshots. Later condominium-book changes cannot alter old quorum or votes.

### Exact decimal arithmetic

The existing `Unit::idealParts` has four decimal places, and co-ownership multiplication can require more precision. Phase 9 therefore stores legal calculation values as fixed-precision decimal strings/Doctrine `decimal` values, normally `DECIMAL(14,8)` percentage points.

All arithmetic/comparisons use BCMath (`ext-bcmath`) or an equivalent exact-decimal helper introduced explicitly by the implementation plan. Binary floating point is forbidden for legal threshold decisions.

Examples:

- `51.00000000` means 51% of common ideal parts;
- a unit with `8.0000` ideal parts and a 50% co-owner yields exactly `4.00000000` represented ideal parts.

The service never rescales known values merely to force a building total to 100%.

### Formal boundaries are immutable

- convening freezes the ordinary agenda, electorate and legal-rule snapshots;
- meeting close freezes in-meeting attendance/proxies/votes;
- minutes finalization freezes the final meeting record;
- corrections after finalization are addenda, never silent rewrites.

### Phase 8 documents are reused, Phase 8 receipts are not legal service

Invitation, minutes and evidence use private `Document` storage. `AnnouncementReceipt` is never treated as statutory invitation/posting proof.

## Authorization and document access

All routes require authentication.

### General Assembly management

`ROLE_MANAGER`, `ROLE_CONTROLLER`, and `ROLE_ADMIN` may manage Phase 9.

`ROLE_CONTROLLER` is intentionally included because the legal framework permits the controller/control board to convene a General Assembly and the project already treats controller access as privileged condominium-governance access.

`ROLE_CASHIER` alone has no meeting-management authority.

### Resident access

Active authenticated users may view meetings from `CONVENED` onward, resident-visible invitation documents, published decisions and finalized minutes.

There is **no resident self-service formal vote POST** in Phase 9. Formal votes are recorded by an authorized operator from attendance/proxy/absentee evidence; a normal authenticated session is not treated as statutory identity/signature proof.

### Governance evidence documents

Phase 9 adds a fourth `DocumentAccessLevel`:

- `GOVERNANCE` — `ROLE_CONTROLLER`, `ROLE_MANAGER`, `ROLE_ADMIN`.

The final access matrix becomes:

- `RESIDENTS` — every active authenticated user;
- `FINANCE` — cashier/controller/manager/admin;
- `GOVERNANCE` — controller/manager/admin;
- `MANAGEMENT` — manager/admin.

Proxy documents, posting evidence and absentee declarations use `GOVERNANCE` by default. Invitation and finalized minutes use `RESIDENTS`.

This avoids exposing personal evidence to all residents or to the cashier while still allowing a controller to conduct a meeting. Evidence must not bypass `DocumentAccessPolicy` through special download routes.

Phase 9 adds `MEETING_PROXY` to `DocumentCategory`; existing `MEETING_INVITATION` and `MEETING_MINUTES` are reused.

Every POST mutation is CSRF-protected. Twig link visibility is never the security boundary.

## General Assembly lifecycle

`GeneralAssemblyStatus`:

- `DRAFT`;
- `CONVENED`;
- `IN_PROGRESS`;
- `CLOSED`;
- `MINUTES_FINALIZED`.

### DRAFT

Editable:

- title/internal reference;
- initiator and convening basis;
- scheduled time and place;
- optional online-meeting reference;
- ordinary agenda;
- draft resolution wording;
- decision kinds and proposed legal rule snapshots.

Not resident-visible.

### CONVENED

Convening is transactional and immutable. It:

1. validates required meeting metadata;
2. freezes the ordinary agenda;
3. creates the electorate snapshot for the meeting reference date;
4. snapshots quorum and majority rules;
5. generates/stores the invitation;
6. records actor/time;
7. makes the meeting resident-visible.

Ordinary agenda items cannot then be edited, deleted or reordered.

### IN_PROGRESS

Authorized operators may register attendance, proxies, online participation, quorum checks, emergency items and formal votes.

A meeting may enter `IN_PROGRESS` before a valid quorum is established so facts can be recorded, but no UI may label the meeting legally quorate without a persisted `VALID` check. `REVIEW_REQUIRED` is always visibly different from valid.

### CLOSED

Closing freezes in-meeting attendance/proxies/votes.

Agenda items without an absentee extension get their final resolution snapshot on close. Eligible items for which the assembly explicitly opens absentee voting transition to `ABSENTEE_WINDOW` and defer final resolution until that window closes.

### MINUTES_FINALIZED

Finalization is one transactional operation. It validates that all agenda items are resolved or explicitly `REVIEW_REQUIRED`, all absentee windows are closed, and required minutes metadata exist; then it renders/stores the final minutes document, links it, records finalizer/time and transitions the meeting.

If PDF/storage/persistence fails, the meeting remains `CLOSED`; there is no state in which `MINUTES_FINALIZED` exists without its final document.

## Core domain model

### GeneralAssembly

Key fields:

- `id`;
- `status`;
- `title`;
- `scheduledAt` UTC;
- `timezoneSnapshot` (for this product normally `Europe/Sofia`);
- `referenceDate` local `date_immutable` used for ownership snapshot;
- `place`;
- nullable `onlineMeetingReference`;
- `conveningBasis`;
- `initiatorDisplayName` snapshot;
- nullable `initiatorUser`;
- `createdBy`, `createdAt`;
- nullable `convenedBy`, `convenedAt`;
- nullable `startedBy`, `startedAt`;
- nullable `closedBy`, `closedAt`;
- nullable `minutesFinalizedBy`, `minutesFinalizedAt`;
- nullable `minutesDueOn` local `date_immutable` snapshot;
- nullable `invitationDocument`;
- nullable `minutesDocument`;
- nullable chairperson/secretary name snapshots;
- optional formal notes.

Transitions validate state and timestamp order.

### AssemblyConveningBasis

Initial enum:

- `MANAGER_OR_BOARD`;
- `CONTROLLER_OR_CONTROL_BOARD`;
- `OWNERS_REQUEST_20_PERCENT`;
- `OWNERS_AFTER_MANAGER_INACTION`;
- `URGENT_OWNER_OR_USER`;
- `FIRST_ASSEMBLY`;
- `OTHER_LEGAL_BASIS`.

`OTHER_LEGAL_BASIS` requires explanatory text. This field records the claimed basis; it does not prove legal validity.

### AssemblyAgendaItem

Fields:

- `assembly`;
- unique positive `position` within assembly;
- `title`;
- optional `description`;
- `draftResolutionText`;
- `finalResolutionText` once opened;
- `kind: AssemblyDecisionKind`;
- majority-rule snapshot fields;
- `isEmergency`;
- nullable `emergencyReason`;
- `status: AgendaItemStatus`;
- nullable `openedAt`, `closedAt`;
- nullable one-to-one `resolution`.

`AgendaItemStatus`:

- `PLANNED`;
- `OPEN`;
- `ABSENTEE_WINDOW`;
- `RESOLVED`.

Ordinary items become immutable at convening. Emergency items may be created only during `IN_PROGRESS`, require a non-blank reason and are marked explicitly in minutes.

### AssemblyDecisionKind

Initial controlled categories:

- `ORDINARY`;
- `ELECTION_OR_REMOVAL`;
- `HOUSE_RULES`;
- `MAJOR_REPAIR_OR_RENOVATION`;
- `EU_OR_PUBLIC_FUNDING`;
- `USE_OR_CHANGE_OF_COMMON_PARTS`;
- `RIGHT_OF_USE_OR_BUILDING_RIGHT`;
- `MANAGEMENT_MAINTENANCE_COST_DISTRIBUTION`;
- `ASSOCIATION_RELATED`;
- `OTHER_REQUIRES_REVIEW`.

A kind provides a suggested rule, not unquestionable legal advice. Before convening, the operator sees the exact rule/legal-basis snapshot. `OTHER_REQUIRES_REVIEW` cannot produce an automatic accepted/rejected conclusion until a rule is explicitly confirmed.

### AssemblyMajorityRuleSnapshot

Stored directly/embedded on the agenda item, never as a mutable global rule row.

Properties:

- `ruleCode`;
- `denominator: ALL_COMMON_IDEAL_PARTS | REPRESENTED_AT_MEETING | ELIGIBLE_ABSENTEE_UNIVERSE`;
- `thresholdPercent` exact decimal string, e.g. `50.00000000`;
- `comparison: GREATER_THAN | AT_LEAST`;
- `legalBasis`;
- `sourceVersion`/effective-date note;
- `requiresLegalReview`.

The exact distinction between `>` and `>=` is preserved.

### AssemblyElectorateEntry

Immutable ownership/voting-weight snapshot.

Each entry represents one ownership principal's portion of one unit's common ideal parts.

Fields:

- `assembly`;
- nullable source `unit` and `unitRelation` references for traceability;
- `unitDesignationSnapshot`;
- `principalType: PERSON | LEGAL_ENTITY`;
- nullable source `person`;
- `principalNameSnapshot`;
- nullable legal-entity identifier snapshot where applicable;
- `relationTypeSnapshot`;
- `ownershipSharePercentSnapshot`;
- `unitIdealPartsPercentSnapshot`;
- `representedIdealPartsPercentSnapshot` as exact `DECIMAL(14,8)`;
- `quorumEligible`;
- nullable `reviewReason`;
- `createdAt`.

The automatic quorum universe is ownership-based. Active owner relations are snapshotted from `UnitRelationType::OWNER`.

A user/polzvatel may still participate where permitted by law/agreement, but the system does not infer legal voting authority from free-text `managementRightsAndObligations`. Such representation is recorded through an explicit attendance authority mode and may require operator/legal confirmation for the specific agenda item.

### Electorate integrity

`AssemblyElectorateSnapshotService` checks:

- active units;
- active ownership relations as of `referenceDate`;
- common ideal-parts values;
- co-owner ownership shares;
- overlapping/contradictory owner relations;
- exact represented weight per principal;
- total known common ideal parts.

Missing/ambiguous material data create `reviewReason` and can force quorum/resolution to `REVIEW_REQUIRED`.

### AssemblyAttendance

One current attendance record per `(assembly, electorateEntry)` enforced by a database unique constraint.

Fields:

- `assembly`;
- `electorateEntry`;
- `mode: IN_PERSON | ONLINE | BY_PROXY | STATUTORY_USER_AUTHORITY`;
- nullable known `representativePerson`;
- nullable representative name snapshot;
- `registeredBy`, `registeredAt`;
- nullable `leftAt`;
- optional notes.

`STATUTORY_USER_AUTHORITY` records a user/polzvatel acting under claimed statutory/contractual management authority rather than as owner/proxy. The UI requires explicit authority note/confirmation and never silently infers it from free text.

Corrections before meeting close use `AssemblyAttendanceChange` audit rows rather than silent edits.

### AssemblyAttendanceChange

Append-only audit row containing attendance, old/new mode/state, reason, actor and timestamp.

### AssemblyProxy

Fields:

- `assembly`;
- `principalEntry`;
- nullable known `representativePerson`;
- representative name snapshot;
- `authorityKind`;
- `evidenceDocument` with `GOVERNANCE` access;
- `registeredBy`, `registeredAt`;
- optional notes;
- nullable `revokedAt`, `revokedBy`, `revocationReason` for a pre-close correction.

Rules:

- one principal cannot have two effective proxies;
- principal cannot be simultaneously personally/online represented and proxied;
- representative cannot exceed the statutory maximum represented principals where applicable;
- revocation/replacement is explicit and audited;
- no change after meeting close.

### AssemblyQuorumRuleSnapshot

Properties:

- `firstCallThresholdPercent` current baseline `51.00000000`;
- `delayedCallThresholdPercent` current baseline `26.00000000`;
- `dominantOwnerTriggerPercent` current baseline `51.00000000` with `GREATER_THAN` semantics;
- `dominantOwnerRequiredThresholdPercent` current baseline `75.00000000`;
- `legalBasis`;
- `sourceVersion`;
- `requiresLegalReview`.

### AssemblyQuorumCheck

Immutable append-only row:

- `assembly`;
- `kind: FIRST_CALL | DELAYED_CALL | MANUAL_REVIEW`;
- `checkedAt`;
- exact `representedIdealPartsPercent`;
- exact `requiredIdealPartsPercent`;
- `ruleCode`;
- `result: VALID | INVALID | REVIEW_REQUIRED`;
- calculation explanation snapshot;
- `checkedBy`.

Attendance changes never rewrite previous checks; a new check is added.

### AssemblyVote

Formal vote row:

- `agendaItem`;
- `electorateEntry`;
- `choice: FOR | AGAINST | ABSTAIN`;
- exact `weightIdealPartsPercentSnapshot`;
- `castMode: IN_PERSON | ONLINE | PROXY | STATUTORY_USER_AUTHORITY | ABSENTEE_DECLARATION`;
- nullable `proxy`;
- nullable `absenteeDeclaration`;
- `recordedBy`, `recordedAt`.

One effective vote per `(agendaItem, electorateEntry)` is enforced. Vote weight is derived from the electorate snapshot; operators never type arbitrary weight.

### AssemblyVoteCorrection

Before an item is resolved, a mistaken vote may be replaced only through an explicit correction operation recording old choice, new choice, reason, actor and time. After item resolution, normal corrections are forbidden.

### AssemblyResolution

One immutable resolution per resolved agenda item:

- final resolution text;
- exact `forIdealParts`, `againstIdealParts`, `abstainIdealParts`;
- exact denominator and threshold used;
- rule code and legal-basis snapshot;
- `result: ACCEPTED | REJECTED | REVIEW_REQUIRED`;
- calculator actor/time metadata.

The persisted result does not change if future code or law changes.

### AssemblyAbsenteeWindow

Explicit entity/table, never an implicit status-only concept.

Fields:

- `assembly`;
- `openedAt`, `openedBy`;
- `deadlineAt`;
- `closedAt`, `closedBy` nullable;
- legal-basis/source note;
- relation to exactly the eligible agenda items participating in that window.

Only one effective absentee window per meeting is needed in Phase 9.

### AssemblyAbsenteeDeclaration

Fields:

- `assembly`;
- `electorateEntry`;
- `window`;
- `evidenceDocument` with `GOVERNANCE` access;
- `submittedAt`;
- `registeredBy`;
- `signatureModeSnapshot: HAND_SIGNED | ELECTRONIC_DECLARATION_RECORDED`;
- optional verification/notes.

Declaration vote rows record `FOR / AGAINST / ABSTAIN` for eligible agenda items.

Vhod records that an electronic declaration was supplied but does not cryptographically certify its signature.

Rules:

- window must be open;
- item must be eligible;
- deadline enforced;
- evidence required;
- no duplicate effective vote for the same principal/item;
- no new declarations after close.

### AssemblyInvitationPosting

Separate legal-notification evidence:

- `assembly`;
- `postedAt`;
- `postingPlace`;
- `confirmedBy`;
- optional `evidenceDocument` with `GOVERNANCE` access;
- optional notes.

UI distinguishes `Published in Vhod` from `Physical posting recorded`. `AnnouncementReceipt` is never called proof of statutory service.

### AssemblyMinutesCorrection

Append-only finalized-meeting addendum:

- `assembly`;
- `reason`;
- `document`;
- `recordedBy`, `recordedAt`.

It does not mutate votes or historical results.

## Application services

### GeneralAssemblyAccessPolicy

- `canManage(User)` — manager/controller/admin;
- `canViewResident(User, GeneralAssembly)` — active user + resident-visible state.

### GeneralAssemblyService

- create/edit draft;
- convene transactionally under pessimistic lock;
- invoke electorate snapshot;
- freeze agenda/rules;
- start/close meeting;
- enforce lifecycle boundaries.

Minutes finalization itself is delegated to `AssemblyMinutesService`.

### AssemblyElectorateSnapshotService

- load active ownership as of reference date;
- calculate co-owner represented common ideal parts exactly;
- create immutable entries;
- surface readiness/review warnings.

### AssemblyInvitationService

- render invitation HTML/PDF using existing Dompdf setup;
- persist `MEETING_INVITATION` / `RESIDENTS` document;
- link invitation;
- record physical posting separately.

### AssemblyAttendanceService

- register attendance modes;
- enforce one effective representation per principal;
- record explicit corrections/leave state with audit rows.

### AssemblyProxyService

- validate `GOVERNANCE` evidence;
- enforce proxy count and duplicate-representation rules;
- revoke/replace explicitly before close.

### AssemblyQuorumCalculator and AssemblyQuorumService

Pure calculator + persistence service. Calculator uses exact decimal arithmetic, applies the snapshotted normal/delayed/dominant-owner rule, counts each ownership weight once and returns `VALID`, `INVALID` or `REVIEW_REQUIRED`.

### AssemblyVotingService

- open item and freeze final resolution wording;
- record votes from effective representation;
- create explicit vote corrections before resolution;
- resolve immediately when no absentee extension applies;
- transition eligible items into `ABSENTEE_WINDOW` when the meeting opens that mechanism.

### AssemblyResolutionCalculator

Pure exact-decimal calculator from votes + snapshotted majority rule. It preserves denominator type, threshold and `>`/`>=` semantics.

### AssemblyAbsenteeVotingService

- open one meeting absentee window;
- bind eligible agenda items;
- register evidence/declaration votes;
- enforce deadline and uniqueness;
- close the window and resolve deferred items.

### AssemblyMinutesService

- build structured minutes model;
- render print/PDF;
- calculate/store `minutesDueOn` compliance snapshot;
- transactionally store/link `MEETING_MINUTES` / `RESIDENTS` document and transition to `MINUTES_FINALIZED`;
- append later corrections/addenda without rewriting history.

## Invitation workflow

Generated invitation includes the application-held formal data:

- convening person/body;
- date/time/place;
- online participation reference where applicable;
- ordered agenda;
- draft resolution wording where required/appropriate;
- absentee-voting proposal information where relevant.

Invitation is stored privately as `MEETING_INVITATION`, access `RESIDENTS`.

The physical posting act is captured only by `AssemblyInvitationPosting`, not by an app receipt.

## Live meeting workflow

Before start, the workbench shows:

- total active units;
- electorate entries;
- total known ideal parts;
- unresolved electorate warnings.

Attendance list shows unit, principal, exact weight, attendance mode, representative and warnings.

The displayed live represented percentage is informational. Clicking `Провери кворум` persists an immutable `AssemblyQuorumCheck`.

Only one agenda item is opened at a time in the Phase 9 UI for operator accuracy.

Votes are recorded against electorate entries, not user accounts, because co-owners, proxies and legal entities break the one-account/one-vote assumption.

## Absentee voting

Absentee voting is optional and only available when:

- the meeting explicitly opens it;
- the decision kind/rule snapshot marks the item legally eligible;
- the declaration is registered inside the stored statutory window;
- private evidence exists.

There is no resident button that turns an ordinary login into a legally binding declaration.

## Minutes

The draft minutes view contains at minimum:

- meeting identification/time/place;
- initiator/convening basis;
- chairperson/secretary when entered;
- quorum checks and applied rule;
- attendance and online participation;
- proxy representation;
- represented ideal parts;
- agenda and emergency-item reasons;
- exact resolution wording;
- vote totals and results;
- absentee declaration summary when used;
- `REVIEW_REQUIRED` warnings.

Finalization is blocked while any absentee window remains open or any agenda item lacks a deterministic result/review status.

Finalized PDF is stored as `MEETING_MINUTES`, access `RESIDENTS`. Generating/storing/linking the document and transitioning status form one logical transaction with compensating file cleanup on persistence failure, following the existing private-document pattern.

## Persistence and integrity

New tables are expected to include:

- `general_assembly`;
- `assembly_agenda_item`;
- `assembly_electorate_entry`;
- `assembly_attendance`;
- `assembly_attendance_change`;
- `assembly_proxy`;
- `assembly_quorum_check`;
- `assembly_vote`;
- `assembly_vote_correction`;
- `assembly_resolution`;
- `assembly_invitation_posting`;
- `assembly_absentee_window`;
- `assembly_absentee_window_item`;
- `assembly_absentee_declaration`;
- `assembly_absentee_declaration_vote`;
- `assembly_minutes_correction`.

Important guarantees:

- unique agenda position per assembly;
- unique `(assembly_id, electorate_entry_id)` attendance row;
- one effective proxy per principal via transaction/locking plus supporting indexes;
- one effective vote per `(agenda_item_id, electorate_entry_id)`;
- one resolution per agenda item;
- one effective absentee window per meeting;
- one declaration per `(window_id, electorate_entry_id)` unless explicitly replaced through an audited correction path;
- audit-sensitive FKs `ON DELETE RESTRICT`;
- no cascade-remove of legal-history rows;
- no hard-delete service/controller flow.

Where conditional uniqueness cannot be expressed portably in MariaDB/Doctrine, application services use transactions + pessimistic locks and the schema provides the strongest supporting indexes/unique constraints.

## Transactions and concurrency

Pessimistic locking is required for formal race-sensitive operations:

- convene;
- start/close meeting;
- proxy registration/revocation;
- attendance correction when it affects representation;
- quorum check persistence;
- vote record/correction/item resolution;
- absentee-window open/close and declarations;
- minutes finalization.

Double submits must never duplicate electorate snapshots, invitations, votes, resolutions, windows, declarations or minutes.

## Error handling

Controlled validation errors include:

- editing frozen agenda;
- convening with missing metadata;
- material electorate ambiguity;
- duplicate representation;
- proxy without governance evidence;
- proxy-count violation;
- vote from a non-effective representation;
- duplicate vote;
- vote-weight mismatch;
- vote after item resolution;
- absentee declaration outside window;
- ineligible absentee item;
- minutes finalization with open windows or incomplete items.

Management validation errors normally render 422. Management authorization failures are 403. Guessed protected resident/evidence IDs use 404 where existence itself is sensitive.

## HTTP/UI surface

### Resident

- `GET /assemblies`;
- `GET /assembly/{id}`;
- `GET /assembly/{id}/minutes` after finalization;
- existing authenticated Phase 8 document download route.

No resident formal-vote POST route.

### Management preparation

- assembly list/new/edit;
- agenda add/edit/reorder while draft;
- convene;
- invitation preview/PDF;
- posting evidence.

### Management conduct workbench

- attendance registration/correction;
- proxy registration/revocation;
- quorum check;
- emergency agenda item;
- agenda open;
- vote record/correction;
- agenda resolve or defer to absentee window;
- meeting close.

### Post meeting

- open/register/close absentee workflow where allowed;
- minutes draft/preview;
- finalize minutes;
- append correction/addendum.

## UX rules

The live workbench prioritizes accuracy over novelty.

Header:

- meeting status;
- scheduled/started time;
- latest persisted quorum state;
- represented ideal parts;
- unresolved warnings.

Attendance:

- searchable unit/principal rows;
- exact weight;
- in-person/online/proxy/statutory-user-authority state;
- representative;
- warning state.

Agenda item:

- exact final resolution wording;
- majority-rule and legal-basis snapshot;
- live vote totals clearly marked provisional until resolution;
- explicit `FOR`, `AGAINST`, `ABSTAIN` controls;
- final result only after resolution.

`REVIEW_REQUIRED` is always warning-styled, never green/success-styled.

Resident pages are read-only and visually distinct from Community polls.

## Audit and immutability

Actor/time is retained for:

- convening;
- posting evidence;
- meeting start;
- attendance and corrections;
- proxy registration/revocation;
- quorum checks;
- vote creation/correction;
- agenda resolution;
- meeting close;
- absentee window/declarations;
- minutes finalization;
- addenda.

No normal UI deletes these records.

## Testing strategy

### Domain/entity tests

- valid/invalid lifecycle transitions;
- timestamp order;
- agenda freeze;
- emergency reason required;
- exact-decimal rule validation;
- finalization immutability.

### Electorate tests

- single owner;
- co-owners and exact multiplication;
- legal entity owner;
- historical owner selection by `referenceDate`;
- missing ideal parts;
- missing/invalid co-owner shares;
- overlapping owner relations;
- no silent normalization;
- later source edits do not alter snapshots.

### Quorum tests

- exactly 51% first call valid;
- below 51% invalid;
- exactly 26% delayed call valid;
- below 26% invalid;
- dominant owner >51% triggers 75%;
- duplicate representation cannot inflate quorum;
- material incomplete data -> `REVIEW_REQUIRED`.

### Proxy/attendance tests

- governance evidence required;
- one effective representation per principal;
- in-person + proxy conflict blocked;
- maximum-three representation rule;
- explicit revocation/correction audit;
- close freezes changes.

### Voting tests

- only effective representation may vote;
- exact electorate weight;
- one effective vote per principal/item;
- three vote choices;
- explicit correction before resolution;
- no correction after resolution;
- denominator variants;
- exact `>` vs `>=`;
- accepted/rejected/review-required;
- Community rows have zero effect.

### Absentee tests

- explicit window required;
- only eligible items;
- deadline enforced;
- governance evidence required;
- duplicate effective vote prevented;
- close resolves deferred items;
- login alone cannot create statutory vote.

### Functional/security tests

- resident/cashier cannot manage;
- manager/controller/admin can manage;
- draft hidden from residents;
- convened meeting visible;
- frozen agenda rejected;
- invitation private;
- posting evidence separate from app receipt;
- attendance/proxy/quorum/vote workbench paths;
- minutes generation/finalization;
- governance evidence inaccessible to resident/cashier;
- finalized invitation/minutes resident-visible;
- every POST CSRF-protected;
- guessed protected IDs do not leak.

### PDF tests

- Cyrillic invitation/minutes;
- valid `application/pdf` output/signature;
- remote resources disabled;
- safe filenames;
- private document persistence.

### CI gates

- Composer validation, including `ext-bcmath` platform requirement if BCMath is selected by the implementation plan;
- Symfony container lint;
- Doctrine mapping validation;
- MariaDB migrate -> validate -> rollback -> migrate -> validate;
- focused Phase 9 tests;
- full PHPUnit;
- PHPStan with no Phase 9 ignores;
- final diff review;
- exact-head CI green before merge.

## Acceptance criteria

Phase 9 is complete when:

1. manager/controller/admin can create and prepare a draft meeting;
2. convening freezes ordinary agenda, electorate and legal-rule snapshots;
3. invitation is private, generated and resident-visible after convening;
4. physical posting evidence is recorded separately from app read state;
5. ownership/co-ownership produces exact historical ideal-parts weights;
6. incomplete material data yields `REVIEW_REQUIRED`;
7. attendance supports in-person, online, proxy and explicit statutory-user-authority recording;
8. duplicate representation is prevented and corrections are audited;
9. proxy governance evidence and maximum-representation rules are enforced;
10. quorum checks are immutable and use exact snapshotted rules;
11. formal votes are independent of Community polls;
12. vote weight is derived from electorate snapshot and cannot be duplicated;
13. results preserve denominator, threshold, comparison and legal-basis snapshot;
14. absentee voting exists only for explicit eligible items/windows with evidence/deadline controls;
15. meeting close freezes in-meeting facts;
16. minutes finalization is atomic with private PDF storage;
17. final record is immutable and corrections are append-only;
18. residents can read official meeting material but cannot cast a purported statutory vote through ordinary login;
19. governance evidence remains hidden from residents/cashiers;
20. all Doctrine/MariaDB/PHPUnit/PHPStan/security gates are green.

## Implementation sequencing guidance

The implementation plan should use small TDD tasks in this order:

1. access-level/category extension, lifecycle/rule enums and core meeting/agenda domain;
2. exact-decimal helper and electorate snapshot model/service;
3. quorum rule snapshot and pure calculator;
4. persistence migration/schema integrity;
5. management draft/convening + invitation/posting workflow;
6. attendance/proxy/audit services;
7. live quorum workbench;
8. formal voting + result calculator;
9. absentee window/declaration workflow;
10. minutes generation/finalization/addenda;
11. resident views/navigation;
12. final security/audit/CI hardening.

Exact task/file boundaries are defined in the implementation plan after this design is approved.