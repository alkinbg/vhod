# Phase 9 — General Assembly design

## Goal

Implement roadmap Phase 9 as a legally aware, authenticated General Assembly workflow for one residential entrance. The slice must support preparation, convening, agenda control, attendance, proxies, quorum, weighted voting, resolutions and minutes while preserving a strict separation from informal Community polls.

The design is intentionally narrower than a generic meeting, election, e-signature or video-conferencing platform. Vhod records and validates the formal workflow and its evidence; it does not provide its own videoconference service, qualified electronic signature authority, notarization service or public voting portal.

The implementation must preserve historical truth. Ownership, represented ideal parts, attendance, quorum rules, majority rules and voting results are snapshotted into the meeting record so later edits to the condominium book or later legal changes do not rewrite an old General Assembly retroactively.

## Current legal baseline and safety boundary

The design was checked on 2026-09-09 against official Bulgarian sources, including the current MRRB page for the Condominium Ownership Management Act (ЗУЕС), marked as amended through State Gazette issue 49 of 17 June 2025, and the National Assembly materials for the amendments introducing the current quorum thresholds, online participation and absentee voting.

The current implementation baseline includes these rules:

- ordinary first-call quorum is at least 51% of ideal parts represented;
- if that quorum is not present, the meeting may proceed one hour later when at least 26% of ideal parts are represented;
- when one natural or legal person owns more than 51% of the common ideal parts, the special quorum rule requires at least 75% represented;
- one proxy may represent at most three owners and/or users under the statutory proxy rule;
- online participation may be recorded when the meeting itself is conducted with an external online-meeting facility;
- statutory absentee voting is available only for the decision categories allowed by law and runs through a declaration workflow after the meeting within the statutory period;
- the meeting minutes have a statutory preparation/publication workflow and deadline.

These rules are not scattered as hard-coded controller constants. The legal rule actually applied to a meeting or resolution is snapshotted with a machine-readable rule code, numeric threshold/denominator semantics and a human-readable legal-basis note.

Vhod is an operational aid, not legal counsel and not an evidentiary authority. It must never claim that an electronic action inside Vhod by itself proves valid statutory service, identity, qualified electronic signature, notarization or court-proof compliance.

If required source data are incomplete or inconsistent, Vhod must fail closed for automatic legal conclusions and display `legal review required` instead of inventing a quorum or majority result.

## Scope

Phase 9 includes:

- General Assembly draft lifecycle;
- meeting initiator and convening basis;
- meeting date/time/place and optional external online-meeting reference;
- ordered agenda and draft resolutions;
- controlled emergency agenda addition with explicit reason;
- invitation generation using the Phase 8 private document infrastructure;
- invitation-posting evidence and timestamp;
- electorate/ownership snapshot for the meeting;
- attendance registration;
- online attendance registration;
- proxy registration and proxy evidence document;
- represented ideal-parts calculation;
- quorum checks and persisted quorum snapshots;
- decision/majority rule snapshots per agenda item;
- formal `FOR / AGAINST / ABSTAIN` voting separate from Community polls;
- prevention of double representation/double voting;
- resolution result calculation;
- optional statutory absentee-voting follow-up for legally eligible decisions;
- meeting closing;
- minutes drafting and finalization;
- generated printable/PDF invitation and minutes;
- linking final invitation/minutes documents to the existing private Document library;
- resident read-only view of convened/finalized meeting material;
- management/controller working UI for conducting the meeting;
- full audit-safe history with no hard-delete workflow.

## Non-goals

The following are explicitly out of scope:

- building an online video-conferencing system;
- hosting WebRTC streams;
- verifying qualified electronic signatures cryptographically;
- issuing electronic signatures;
- notarization;
- public anonymous meeting URLs;
- secret-ballot elections;
- cryptographic/blockchain voting;
- remote identity-proofing/KYC;
- generalized parliamentary procedure;
- generalized election campaigns;
- arbitrary multi-building or multi-tenant meeting administration;
- integration with municipal/state registers;
- automatic legal advice about whether a controversial decision is lawful;
- automatic court challenge workflows;
- automatic cancellation or erasure of finalized meeting history;
- using Community poll entities/tables/services for formal voting.

## Core design principles

### 1. Formal voting is a distinct bounded context

`CommunityPollVote` remains informal neighbour opinion. General Assembly voting gets separate entities, repositories, services, routes and UI. No Community vote row can ever be interpreted as a formal vote.

### 2. Historical truth beats live joins

Meeting legality must not depend on the current `Unit`, `UnitRelation` or `Person` state after convening. The meeting stores an immutable electorate snapshot and rule snapshots.

### 3. Legal conclusion requires complete inputs

Quorum and majority calculators operate only when the represented ideal-parts universe is sufficiently known for the applicable rule. Missing or contradictory ownership data produces `REVIEW_REQUIRED`, not a fabricated percentage.

### 4. No silent mutation after formal boundaries

Convening freezes the ordinary agenda and electorate snapshot. Closing freezes attendance and in-meeting votes. Finalizing minutes freezes the final record. Corrections after finalization are appended as explicit correction records/documents rather than rewriting history.

### 5. Reuse Phase 8 documents, not Phase 8 notification semantics

Invitation/minutes/proxy evidence use private `Document` storage. `AnnouncementReceipt` is never reused as statutory invitation-service proof.

## Roles and authorization

All Phase 9 routes require authentication.

### Management rights

Users with any of these roles may manage the General Assembly workflow:

- `ROLE_MANAGER`;
- `ROLE_CONTROLLER`;
- `ROLE_ADMIN`.

This deliberately includes `ROLE_CONTROLLER` because the statutory framework permits the control board/controller to convene a General Assembly and the project already treats controller access as privileged condominium-governance access.

`ROLE_CASHIER` alone has no Phase 9 management authority.

### Resident rights

Every active authenticated user may view meetings that have reached the public-to-residents `CONVENED` state, together with resident-visible invitation documents, published decisions and finalized minutes.

Residents do not receive a self-service formal vote button in Phase 9. Formal voting is recorded by an authorized meeting operator from verified attendance/proxy/absentee evidence. This avoids pretending that a normal Vhod login is equivalent to statutory identity verification or signature.

### Server-side enforcement

Twig visibility is presentation only. Controllers/application services enforce permissions for every mutation and every management detail route.

All POST mutations are CSRF-protected.

## General Assembly lifecycle

`GeneralAssemblyStatus` has exactly these values:

- `DRAFT`;
- `CONVENED`;
- `IN_PROGRESS`;
- `CLOSED`;
- `MINUTES_FINALIZED`.

### DRAFT

Management may edit:

- title/internal reference;
- initiator;
- convening basis;
- meeting date/time/place;
- online meeting reference if relevant;
- ordinary agenda;
- draft resolutions;
- proposed majority-rule classifications.

No resident visibility and no formal legal snapshot exists yet.

### CONVENED

Convening is an immutable boundary. The service:

- validates required meeting metadata;
- freezes and snapshots the ordinary agenda;
- creates the electorate snapshot as of the meeting/legal reference date;
- snapshots the applicable quorum rule configuration;
- generates/links the invitation document;
- records who convened the meeting and when;
- makes the meeting visible to active residents.

After convening, ordinary agenda items cannot be silently edited, removed or reordered.

### IN_PROGRESS

The meeting operator may:

- register attendance;
- register proxy representation;
- record external online participation;
- run quorum checks;
- add an explicitly flagged emergency agenda item with written reason;
- open agenda items for voting;
- record formal votes;
- close individual agenda items.

The meeting cannot start unless there is at least one persisted quorum check. If automatic validity cannot be determined, management may continue recording facts but the UI must clearly show `legal review required`; it must not show a green valid-quorum badge.

### CLOSED

Closing freezes in-meeting attendance and formal votes. The system computes persisted outcome snapshots for each agenda item.

If one or more agenda items are configured as legally eligible for absentee voting and the meeting explicitly resolves to use that mechanism, those items remain in an `ABSENTEE_WINDOW` decision state until the window closes. The General Assembly itself stays `CLOSED`; the post-meeting absentee declarations are a separate bounded follow-up process attached to the closed meeting.

### MINUTES_FINALIZED

Finalization is allowed only when:

- all vote windows are closed;
- every agenda item has a persisted result or explicit `REVIEW_REQUIRED` outcome;
- minutes fields required by the application are present;
- the final minutes document has been generated/linked.

After finalization, meeting facts, electorate, attendance, proxies, votes and result snapshots are immutable through normal UI/service APIs.

## Domain model

### GeneralAssembly

Represents one formal General Assembly.

Fields:

- `id`;
- `status: GeneralAssemblyStatus`;
- `title`;
- `meetingDate` local calendar date;
- `scheduledAt` UTC;
- `place`;
- nullable `onlineMeetingReference` plain text/URL metadata;
- `conveningBasis: AssemblyConveningBasis`;
- `initiatorDisplayName` snapshot;
- nullable `initiatorUser: User` when convened by a system user;
- `createdBy: User`;
- `createdAt` UTC;
- nullable `convenedBy: User`;
- nullable `convenedAt` UTC;
- nullable `startedBy: User`;
- nullable `startedAt` UTC;
- nullable `closedBy: User`;
- nullable `closedAt` UTC;
- nullable `minutesFinalizedBy: User`;
- nullable `minutesFinalizedAt` UTC;
- nullable `minutesDeadlineAt` UTC/local-derived timestamp;
- nullable `invitationDocument: Document`;
- nullable `minutesDocument: Document`;
- optional chairperson/secretary names as meeting-time snapshots;
- optional notes relevant to the formal record.

All transition methods validate temporal ordering and current state.

### AssemblyConveningBasis

Controlled enum used to explain why/how the meeting is being convened, initially:

- `MANAGER_OR_BOARD`;
- `CONTROLLER_OR_CONTROL_BOARD`;
- `OWNERS_REQUEST_20_PERCENT`;
- `OWNERS_AFTER_MANAGER_INACTION`;
- `URGENT_OWNER_OR_USER`;
- `FIRST_ASSEMBLY`;
- `OTHER_LEGAL_BASIS`.

`OTHER_LEGAL_BASIS` requires a non-blank explanatory note. The enum is descriptive; it does not independently prove legal validity.

### AssemblyAgendaItem

Fields:

- `id`;
- `assembly`;
- `position` positive integer;
- `title`;
- optional `description`;
- `draftResolutionText`;
- `kind: AssemblyDecisionKind`;
- `majorityRuleSnapshot: AssemblyMajorityRuleSnapshot` as embedded/value fields;
- `isEmergency`;
- nullable `emergencyReason`;
- `status: AgendaItemStatus`;
- nullable `openedAt`;
- nullable `closedAt`;
- nullable `resolution: AssemblyResolution`.

Ordinary items are frozen at convening. Emergency items may only be created after start, require `isEmergency=true` and a reason, and are visibly marked in minutes.

### AssemblyDecisionKind

A controlled application enum representing classes of decisions for which the legal majority semantics differ. Initial values should cover the practical ZUES categories required by the entrance without pretending to encode every possible legal dispute.

Initial categories:

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

Each kind maps at draft time to a suggested majority rule, but the operator must see the rule and legal-basis text before convening. `OTHER_REQUIRES_REVIEW` cannot produce an automatic accepted/rejected legal conclusion until a rule is explicitly supplied and confirmed.

### AssemblyMajorityRuleSnapshot

This is not a shared mutable configuration row. It is a snapshot copied onto each agenda item before convening.

Fields/value properties:

- `ruleCode` stable machine code;
- `denominator: AssemblyVoteDenominator`;
- `thresholdBasisPoints` integer to avoid floating-point comparison;
- `comparison: MajorityComparison` (`GREATER_THAN` or `AT_LEAST`);
- `legalBasis` human-readable text;
- `requiresLegalReview` boolean;
- `sourceVersion`/effective-date note.

`AssemblyVoteDenominator` initially supports:

- `ALL_COMMON_IDEAL_PARTS`;
- `REPRESENTED_AT_MEETING`;
- `ELIGIBLE_ABSENTEE_UNIVERSE` when specifically required by the configured legal rule.

Percentages are stored/calculated as integer basis points or higher fixed precision, never binary floats.

### AssemblyElectorateEntry

Immutable meeting snapshot of the voting/representation universe.

Fields:

- `id`;
- `assembly`;
- nullable source `unit: Unit` reference for traceability;
- nullable source `unitRelation: UnitRelation` reference for traceability;
- `unitDesignationSnapshot`;
- `principalType: PERSON | LEGAL_ENTITY`;
- nullable `person: Person` source reference;
- `principalNameSnapshot`;
- nullable `principalIdentifierSnapshot` where appropriate and permitted;
- `relationTypeSnapshot`;
- nullable `ownershipShareSnapshot`;
- `unitIdealPartsSnapshot`;
- `representedIdealPartsSnapshot`;
- `eligibleToVote`;
- nullable `reviewReason`;
- `createdAt` UTC.

The snapshot distinguishes ownership share inside an individual unit from the unit's common ideal-parts percentage. For example, if a unit has 8% common ideal parts and two co-owners each own 50% of the unit, each electorate entry represents 4% of common ideal parts.

No later condominium-book edit changes these values.

### Electorate integrity

At convening, `AssemblyElectorateSnapshotService` validates:

- active unit status;
- active ownership relations as of the meeting reference date;
- ownership shares within each unit;
- common ideal-parts data;
- duplicate/overlapping active owner relations;
- total represented ownership universe.

If a unit has missing ideal parts, ambiguous co-ownership shares or contradictory active owner relations, affected electorate entries are marked for review and automatic quorum/majority conclusions are disabled where the missing data could change the result.

The service never rescales known percentages to force the building total to 100%.

### AssemblyAttendance

Represents attendance by one principal/electorate entry.

Fields:

- `id`;
- `assembly`;
- `electorateEntry`;
- `mode: AssemblyAttendanceMode`;
- nullable `representativePerson: Person` when represented by another known person;
- nullable `representativeNameSnapshot` for an external/non-user representative;
- `registeredBy: User`;
- `registeredAt` UTC;
- nullable `leftAt` UTC;
- optional notes.

Modes:

- `IN_PERSON`;
- `ONLINE`;
- `BY_PROXY`.

One electorate entry can have at most one active attendance representation at a time.

### AssemblyProxy

Represents one proxy authority used for the meeting.

Fields:

- `id`;
- `assembly`;
- `principalEntry`;
- nullable known `representativePerson: Person`;
- `representativeNameSnapshot`;
- nullable `representativeIdentifierSnapshot` if legitimately recorded;
- `authorityKind`;
- `evidenceDocument: Document`;
- `registeredBy: User`;
- `registeredAt` UTC;
- optional `notes`.

Rules:

- evidence document must have `RESIDENTS` or a dedicated safe meeting-evidence access policy decided in implementation; it must never become public;
- one principal entry cannot have two active proxies;
- a represented principal cannot simultaneously be marked in-person/online;
- a natural representative cannot exceed the statutory maximum of three represented owners/users when that rule applies;
- proxy registration is immutable after the meeting is closed.

Phase 9 adds `MEETING_PROXY` to `DocumentCategory`.

### AssemblyQuorumRuleSnapshot

Stored on the meeting at convening.

Fields/value properties:

- `firstCallThresholdBasisPoints` (current baseline 5100);
- `delayedCallThresholdBasisPoints` (current baseline 2600);
- `dominantOwnerTriggerBasisPoints` (current baseline >5100);
- `dominantOwnerRequiredThresholdBasisPoints` (current baseline 7500);
- `legalBasis`;
- `sourceVersion`;
- `requiresLegalReview`.

The service detects whether the dominant-owner special rule applies from the electorate snapshot.

### AssemblyQuorumCheck

Immutable persisted check.

Fields:

- `id`;
- `assembly`;
- `kind: FIRST_CALL | DELAYED_CALL | MANUAL_REVIEW`;
- `checkedAt` UTC;
- `representedIdealPartsBasisPoints` fixed-precision value;
- `requiredIdealPartsBasisPoints` fixed-precision value;
- `ruleCode`;
- `result: VALID | INVALID | REVIEW_REQUIRED`;
- `calculationDetails` structured/simple text snapshot;
- `checkedBy: User`.

A later attendance change never mutates an old quorum check; a new check is appended.

### AssemblyVote

Formal vote row, never shared with Community.

Fields:

- `id`;
- `agendaItem`;
- `electorateEntry`;
- `choice: AssemblyVoteChoice` (`FOR`, `AGAINST`, `ABSTAIN`);
- `weightIdealPartsSnapshot`;
- `castMode: IN_PERSON | ONLINE | PROXY | ABSENTEE_DECLARATION`;
- nullable `proxy: AssemblyProxy`;
- nullable `absenteeDeclaration: AssemblyAbsenteeDeclaration`;
- `recordedBy: User`;
- `recordedAt` UTC.

Database uniqueness ensures one effective vote per electorate entry per agenda item.

A vote is accepted only when the principal is validly represented for that mode and the vote weight exactly matches the electorate snapshot weight.

### AssemblyResolution

Immutable computed result for a closed agenda item.

Fields:

- `id`;
- `agendaItem` one-to-one;
- `finalResolutionText`;
- `forIdealParts`;
- `againstIdealParts`;
- `abstainIdealParts`;
- `denominatorIdealParts`;
- `thresholdIdealParts`;
- `ruleCode`;
- `legalBasisSnapshot`;
- `result: ACCEPTED | REJECTED | REVIEW_REQUIRED`;
- `calculatedAt` UTC;
- `calculatedBy: User`.

The result is derived from snapshotted rule semantics and votes, then persisted so later code/rule changes do not alter historical results.

### AssemblyAbsenteeDeclaration

Represents one statutory post-meeting absentee declaration when permitted.

Fields:

- `id`;
- `assembly`;
- `electorateEntry`;
- `evidenceDocument: Document`;
- `submittedAt` UTC;
- `registeredBy: User`;
- `signatureModeSnapshot: HAND_SIGNED | ELECTRONIC_DECLARATION_RECORDED`;
- optional verification/notes text;
- one or more linked declaration vote choices for eligible agenda items.

Vhod records that an electronic declaration was supplied; it does not cryptographically certify an electronic signature.

The absentee service enforces:

- meeting explicitly opened an absentee-voting window;
- agenda item is marked legally eligible;
- declaration arrives inside the configured statutory deadline;
- principal did not already cast an effective vote for the same agenda item;
- declaration document is retained privately;
- after the window closes, no further declaration can be registered normally.

### AssemblyMinutesCorrection

Append-only correction metadata for a finalized meeting.

Fields:

- `id`;
- `assembly`;
- `reason`;
- `document: Document`;
- `recordedBy: User`;
- `recordedAt` UTC.

It does not mutate old votes/results. It records a correction/addendum artifact and reason.

## Invitation workflow

### Invitation contents

Generated invitation includes at minimum the application-held data required for the workflow:

- who convenes the meeting;
- date;
- time;
- place;
- online participation information when applicable;
- ordered agenda;
- draft resolution text where required/appropriate;
- clear identification of agenda items proposed for absentee-voting eligibility where relevant.

The invitation is rendered to print-friendly HTML/PDF using the existing Dompdf setup and stored through the Phase 8 `DocumentService` as category `MEETING_INVITATION`, access `RESIDENTS`.

### Posting evidence

`AssemblyInvitationPosting` records the physical/legal notification act separately from in-app availability.

Fields:

- `assembly`;
- `postedAt`;
- `postingPlace`;
- `confirmedBy: User`;
- optional `evidenceDocument: Document`;
- optional notes.

The UI must distinguish:

- `Invitation published in Vhod`;
- `Physical posting recorded`;
- any additional notification method recorded as metadata.

A Phase 8 `AnnouncementReceipt` is never labeled as proof of statutory posting/service.

## Meeting start and attendance workflow

Before starting, management sees an electorate readiness summary:

- total active units;
- units with usable ideal-parts data;
- electorate entries;
- total known common ideal parts;
- unresolved electorate warnings.

Attendance UI is optimized for live use:

- searchable unit/principal list;
- one-click in-person/online registration;
- proxy registration flow;
- visible represented ideal parts;
- duplicate-representation prevention;
- real-time informational represented percentage.

The informational live percentage is not itself the legal quorum record. Pressing `Провери кворум` creates an immutable `AssemblyQuorumCheck` snapshot.

## Quorum calculation

`AssemblyQuorumCalculator` is a pure domain service operating on snapshot values.

Inputs:

- quorum rule snapshot;
- electorate snapshot;
- effective attendance/proxy representation at check time;
- check kind/time.

Output:

- represented ideal parts;
- required ideal parts;
- applied rule code;
- `VALID`, `INVALID` or `REVIEW_REQUIRED`;
- explanation.

The calculator:

- never uses binary floating point for legal comparisons;
- detects the dominant-owner special rule;
- counts each electorate weight at most once;
- does not count an absentee declaration in the physical/online meeting quorum unless the applicable legal rule explicitly says so;
- returns `REVIEW_REQUIRED` when unknown/malformed electorate data can alter the outcome.

## Voting and result calculation

### Opening an agenda item

Only one agenda item needs to be active at a time in the management UI, although this is an application UX rule rather than a legal domain requirement.

Opening an item freezes the final resolution wording that is being voted on for that vote round.

### Recording votes

Management records votes against electorate entries, not users.

This is important because:

- one user account is not necessarily one legal voter;
- co-owners may have separate weights;
- a proxy may represent several principals;
- legal entities may be represented by a person who has no Vhod account.

The system derives weight from the electorate snapshot and never accepts arbitrary operator-entered vote weight.

### Changing a vote

Before an agenda item is closed, an authorized operator may correct a mistakenly recorded vote through an explicit replacement operation that records audit metadata (old choice, new choice, actor, timestamp, reason). Silent row overwrite is not allowed.

After the agenda item is closed, normal vote edits are forbidden.

### Result calculation

`AssemblyResolutionCalculator`:

- aggregates effective votes;
- derives the denominator from the rule snapshot;
- applies exact threshold/comparison semantics;
- returns `ACCEPTED`, `REJECTED` or `REVIEW_REQUIRED`;
- persists all numerator/denominator/threshold values used.

A result can be automatically `ACCEPTED`/`REJECTED` only when the rule snapshot and electorate data are complete enough to make the conclusion deterministic.

## Absentee-voting workflow

Phase 9 supports absentee voting only as an explicit optional extension for agenda items configured as legally eligible.

At the in-person/online meeting, management records whether the General Assembly decided to open the absentee window.

If opened:

- the window deadline is persisted;
- only eligible agenda items are included;
- submitted declarations are stored as private documents;
- management records the declaration votes exactly as evidenced;
- the system prevents duplicate effective votes;
- final resolution calculation waits until the window is closed.

There is no resident-facing button that generates a legally binding declaration from a simple login session.

## Minutes workflow

### Draft minutes

After the meeting closes, Vhod generates a structured draft containing:

- meeting identification, date/time/place;
- convening/initiator information;
- chairperson and secretary where entered;
- quorum checks and applied rule;
- attendance list;
- proxy representation and represented ideal parts;
- online participation list;
- agenda;
- proposals/final wording voted on;
- vote totals by ideal parts;
- decisions/results;
- emergency items and reasons;
- absentee-voting window/declaration summary when applicable;
- unresolved legal-review warnings.

### Finalization

Finalization generates a PDF and stores it as `DocumentCategory::MEETING_MINUTES`, `RESIDENTS` access.

The service also snapshots the statutory minutes deadline and displays overdue warnings before finalization. The deadline is informational/compliance support; Vhod does not claim that generating the PDF alone completes every statutory publication/notification step.

After finalization the minutes document is immutable. Corrections use `AssemblyMinutesCorrection` and an addendum document.

## Document integration

Phase 9 reuses Phase 8 `Document`, `DocumentStorage`, `DocumentService` and authenticated download routes.

`DocumentCategory` adds:

- `MEETING_PROXY`.

Existing categories reused:

- `MEETING_INVITATION`;
- `MEETING_MINUTES`.

For other evidence, `OTHER` may be used initially rather than creating a large legal-document taxonomy.

All meeting evidence remains outside `public/`.

## Repository/application services

### GeneralAssemblyAccessPolicy

Responsibilities:

- `canManage(User)` for manager/controller/admin;
- `canViewResident(User, GeneralAssembly)` for active authenticated users when state is resident-visible;
- helper policy decisions for finalized/private evidence where needed.

### GeneralAssemblyService

Responsibilities:

- create/edit draft;
- convene under transaction/pessimistic lock;
- invoke electorate snapshot service;
- freeze agenda/rules;
- start meeting;
- close meeting;
- finalize minutes;
- enforce lifecycle boundaries.

### AssemblyElectorateSnapshotService

Responsibilities:

- load active `Unit`/`UnitRelation` ownership data as of the reference date;
- calculate represented common ideal parts per owner/principal;
- identify incomplete/ambiguous ownership;
- create immutable electorate rows;
- generate readiness/review status.

### AssemblyInvitationService

Responsibilities:

- render invitation HTML/PDF;
- persist private invitation document;
- link it to the meeting;
- record posting evidence through a separate operation.

### AssemblyAttendanceService

Responsibilities:

- register in-person/online attendance;
- register proxy-based attendance;
- prevent duplicate representation;
- end/change active representation before closing with explicit audit operations.

### AssemblyProxyService

Responsibilities:

- validate evidence document;
- enforce one active proxy per principal;
- enforce representative proxy-count rule;
- create immutable proxy records.

### AssemblyQuorumCalculator / AssemblyQuorumService

Pure calculator returns a result; application service persists immutable checks with actor/time.

### AssemblyVotingService

Responsibilities:

- open agenda item;
- record vote from effective representation;
- explicitly correct a vote before close with audit row;
- close agenda item;
- invoke resolution calculator when eligible;
- enforce uniqueness and lifecycle.

### AssemblyResolutionCalculator

Pure deterministic fixed-precision calculation from votes + rule snapshot + electorate/attendance context.

### AssemblyAbsenteeVotingService

Responsibilities:

- open/close statutory absentee window;
- validate item eligibility;
- register declaration evidence;
- prevent duplicate votes;
- trigger final recalculation after closing.

### AssemblyMinutesService

Responsibilities:

- build structured minutes view model;
- render print/PDF;
- store final minutes document;
- enforce finalization prerequisites;
- record later correction/addendum documents without rewriting history.

## Persistence model and integrity

Expected new tables include:

- `general_assembly`;
- `assembly_agenda_item`;
- `assembly_electorate_entry`;
- `assembly_attendance`;
- `assembly_proxy`;
- `assembly_quorum_check`;
- `assembly_vote`;
- `assembly_vote_correction`;
- `assembly_resolution`;
- `assembly_invitation_posting`;
- `assembly_absentee_window` or equivalent explicit window state;
- `assembly_absentee_declaration`;
- declaration-to-agenda vote rows where required;
- `assembly_minutes_correction`.

Important database guarantees:

- unique agenda `position` per assembly;
- one electorate entry identity/source combination per meeting;
- one active/effective attendance representation per electorate entry at the service/database level where feasible;
- one proxy principal per meeting;
- indexed representative identity for proxy-count checks;
- one effective formal vote per `(agenda_item_id, electorate_entry_id)`;
- one resolution per agenda item;
- one absentee declaration per `(assembly_id, electorate_entry_id)` unless an explicit correction model is used;
- audit-sensitive FKs use `ON DELETE RESTRICT`;
- no cascade-remove from General Assembly into legal-history rows;
- no hard-delete controller/service flow.

Where SQL cannot express a temporal/conditional uniqueness rule portably, the application service uses transaction + pessimistic locking and the schema provides the strongest practical supporting unique constraints.

## Transactions and concurrency

Formal transitions use database transactions and pessimistic locking on the `GeneralAssembly` or relevant agenda item.

At minimum, locking is required for:

- convening;
- starting/closing the meeting;
- proxy registration where representative-count limits could race;
- quorum check creation against mutable attendance;
- vote record/correction/agenda close;
- opening/closing absentee voting;
- minutes finalization.

Double-submit behavior must be deterministic and must never duplicate snapshots, invitation documents, votes, resolutions or minutes.

## Error handling

Controlled errors include:

- attempt to edit frozen ordinary agenda after convening;
- attempt to convene without complete required metadata;
- electorate data insufficient for automatic legal calculation;
- duplicate attendance/representation;
- proxy without evidence;
- fourth represented principal for one proxy when the statutory maximum applies;
- vote from a principal not effectively represented;
- duplicate vote;
- arbitrary vote-weight mismatch;
- vote after agenda item close;
- absentee declaration outside window;
- absentee vote for ineligible item;
- finalization while vote windows remain open;
- finalization with missing required minutes fields.

Business validation errors render controlled 422 responses in management forms where appropriate. Authorization failures are 403 on management routes. Guessed resident-inaccessible IDs should use 404 when revealing existence would leak protected meeting/evidence data.

## HTTP/UI flows

Exact route names may adapt to project conventions, but the intended surface is:

### Resident

- `GET /assemblies` — convened/current/finalized meetings visible to residents;
- `GET /assembly/{id}` — meeting detail, agenda, invitation, published outcomes;
- `GET /assembly/{id}/minutes` — finalized minutes view;
- authenticated document downloads through existing Phase 8 route.

No resident POST vote route is introduced.

### Management — preparation

- `GET /management/assemblies`;
- `GET|POST /management/assembly/new`;
- `GET|POST /management/assembly/{id}/edit` while draft;
- agenda add/edit/reorder while draft;
- `POST /management/assembly/{id}/convene`;
- invitation preview/PDF;
- posting evidence form.

### Management — conduct

- meeting workbench route;
- attendance registration;
- proxy registration;
- quorum check;
- emergency agenda item;
- agenda item open;
- vote recording/correction;
- agenda item close;
- meeting close.

### Management — post meeting

- absentee declaration registration where enabled;
- close absentee window;
- minutes draft/preview;
- finalize minutes;
- append correction/addendum.

## UX design

The live meeting workbench must optimize accuracy over visual novelty.

Top area shows:

- meeting status;
- scheduled/started time;
- latest quorum state;
- represented ideal parts;
- unresolved warnings.

Attendance area shows searchable rows:

- unit;
- principal;
- ideal-parts weight;
- present/online/proxy state;
- representative;
- warning state.

Agenda area shows one item at a time with:

- exact resolution text;
- majority rule summary;
- legal basis snapshot;
- live vote totals;
- explicit `FOR`, `AGAINST`, `ABSTAIN` controls;
- result only after close or as clearly labeled provisional data before close.

`REVIEW_REQUIRED` uses a warning treatment and never a green success treatment.

Resident pages are read-only and visually distinct from Community polls.

## Audit and immutability

Phase 9 is intentionally append-heavy.

Important events retain actor/time:

- convening;
- posting evidence;
- meeting start;
- attendance/proxy registration;
- quorum checks;
- vote creation/correction;
- agenda close;
- meeting close;
- absentee declaration registration;
- absentee window close;
- minutes finalization;
- correction/addendum.

No normal UI deletes these rows.

## Testing strategy

### Entity/value tests

Cover:

- lifecycle transitions and invalid transitions;
- timestamp ordering;
- agenda freeze;
- emergency-item reason requirement;
- fixed-precision majority rule validation;
- immutable snapshots;
- minutes finalization immutability.

### Electorate snapshot tests

Cover:

- single owner/unit;
- co-owners with ownership shares;
- legal entity owner;
- historical relation selection as of meeting date;
- missing unit ideal parts;
- missing co-owner shares;
- overlapping owner relations;
- totals not silently normalized;
- later `UnitRelation` edits do not alter meeting snapshot.

### Quorum tests

Cover current baseline scenarios:

- 51% first-call valid;
- below 51% first-call invalid;
- 26% delayed-call valid;
- below 26% delayed-call invalid;
- dominant owner >51% triggers special 75% requirement;
- duplicate representation cannot inflate quorum;
- incomplete material electorate data returns `REVIEW_REQUIRED`.

Rules are tested through snapshot values rather than globally hard-coded expectations only, so historical/custom source versions remain testable.

### Proxy tests

Cover:

- evidence required;
- one principal/one proxy;
- same principal cannot be personally present and proxied;
- maximum-three represented principals rule;
- proxy representation weight equals electorate entry weight;
- closing meeting freezes proxy state.

### Voting tests

Cover:

- only represented principals can vote;
- exact snapshot weight used;
- one effective vote per principal/item;
- three choices;
- explicit correction before close with audit row;
- no correction after close;
- denominator variants;
- `>` versus `>=` threshold semantics;
- accepted/rejected/review-required results;
- Community poll rows have no effect on formal result.

### Absentee-voting tests

Cover:

- only explicitly eligible items;
- window required;
- deadline enforced;
- evidence document required;
- duplicate vote prevented;
- closing window finalizes outcome availability;
- login alone never creates a statutory vote.

### Functional tests

Cover:

- resident cannot access management routes;
- cashier cannot manage assembly;
- manager/controller/admin can manage;
- draft not visible to residents;
- convened meeting visible;
- ordinary agenda locked after convening;
- invitation private/authenticated;
- posting evidence separate from in-app receipt;
- live attendance/proxy/quorum workflow;
- vote workflow;
- minutes generation/finalization;
- finalized minutes resident view/download;
- all POST routes require valid CSRF;
- protected guessed IDs do not leak evidence.

### PDF tests

Cover:

- Bulgarian/Cyrillic invitation and minutes;
- `application/pdf`;
- valid PDF signature;
- no remote resource loading;
- private document persistence/linking;
- safe filenames.

### Schema/CI gates

- Composer validation;
- Symfony container lint;
- Doctrine mapping validation;
- MariaDB migrate → schema validate → rollback → migrate → schema validate;
- focused Phase 9 PHPUnit suites;
- full PHPUnit;
- PHPStan with no Phase 9 ignore rules;
- final PR diff review;
- exact-head CI green before merge.

## Acceptance criteria

Phase 9 is complete when all of the following are true:

1. manager/controller/admin can create a draft General Assembly;
2. draft agenda and resolution text can be prepared before convening;
3. convening creates an immutable electorate and legal-rule snapshot;
4. invitation is generated/stored privately and resident-visible only after convening;
5. physical posting evidence can be recorded separately from app read state;
6. attendance supports in-person, online and proxy modes;
7. duplicate representation is prevented;
8. proxy evidence and maximum-representation checks are enforced;
9. quorum checks are immutable and use fixed-precision snapshot rules;
10. incomplete material ownership data produces `REVIEW_REQUIRED` rather than a fabricated valid result;
11. formal votes are stored independently of Community polls;
12. each vote uses the electorate snapshot weight and cannot be duplicated;
13. majority/result calculation preserves denominator, threshold, comparison and legal-basis snapshot;
14. optional absentee voting works only for explicitly eligible items and with evidence/deadline controls;
15. closed meeting facts/votes cannot be silently edited;
16. minutes can be generated in Bulgarian as print/PDF and stored privately;
17. finalization freezes the meeting record;
18. later corrections are append-only addenda;
19. residents can view convened/finalized material but cannot cast a purported statutory vote through a simple app POST;
20. all security, Doctrine, MariaDB, PHPUnit and PHPStan gates are green.

## Implementation sequencing guidance

The implementation plan should split the phase into small TDD tasks, broadly in this order:

1. lifecycle/rule enums and core meeting/agenda domain;
2. electorate snapshot model/service;
3. legal fixed-precision quorum calculator;
4. persistence migration/schema integrity;
5. management draft/convening workflow and invitation;
6. attendance/proxy model and services;
7. live quorum workflow;
8. formal voting + result calculator;
9. absentee-voting extension;
10. minutes generation/finalization;
11. resident views/navigation;
12. final security/audit/CI hardening.

The exact task boundaries are defined by the implementation plan after this design is approved.