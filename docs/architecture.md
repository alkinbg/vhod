# Vhod architecture

## Product boundary

Vhod is a private internal system for one specific residential entrance. It is not a multi-building product and will never become a SaaS platform.

There is deliberately no tenant abstraction, subscription model, public registration, customer onboarding or cross-building administration.

## Product goals

The system should make the entrance easier to live in and easier to manage:

- residents can see their own obligations, payment history and important information;
- management can maintain the condominium book, charges, payments, expenses and documents without spreadsheets and scattered paper;
- everyone can see common financial summaries, funds, approved expenses and ongoing work;
- official meetings, notices and decisions have a traceable history;
- maintenance, defects and signals can be followed from report to completion;
- neighbours have a private community space for informal communication and mutual help.

## Legal baseline

Implementation must be checked against the current Bulgarian Condominium Ownership Management Act (ЗУЕС), the official MRRB templates and the 2026 regulation for the Unified Information System of Condominium Ownership.

Primary sources:

- MRRB condominium ownership section and official templates: https://www.mrrb.bg/bg/jilistna-politika/etajna-sobstvenost/zakon-za-upravlenie-na-etajnata-sobstvenost/
- State Gazette, Ordinance No. RD-02-20-1 of 5 February 2026: https://dv.parliament.bg/DVWeb/showMaterialDV.jsp?idMat=241331

The application is an operational aid. It must not silently turn an informal action into a legally binding one. When the law requires a formal document, quorum, majority, signature, notification method or deadline, the corresponding workflow must model that requirement explicitly.

## Technical architecture

Use a modular monolith.

### Runtime

- PHP 8.4
- Symfony 8.1
- Doctrine ORM
- MariaDB 10.11+
- Twig
- AssetMapper
- Symfony UX Turbo and Stimulus
- Messenger only for work that benefits from asynchronous execution
- Redis only where caching, locks or queueing provide concrete value

### Quality

- `declare(strict_types=1);` in PHP source files;
- typed properties and return types;
- constructor injection;
- small focused services;
- no speculative abstractions;
- PHPUnit tests for domain behaviour;
- functional tests for permissions and workflows;
- PHPStan in CI;
- Doctrine schema validation in CI;
- immutable/auditable financial history once the finance module is introduced.

## Core identity model

### Unit

A self-contained object relevant to the entrance: apartment, garage, shop or another independent unit.

Important fields:

- designation/number;
- type;
- floor;
- built area;
- ideal parts percentage;
- active state.

A Unit is not itself treated as a person and does not own a login account.

### Person

A natural person known to the entrance administration. Store only data needed for condominium management and communication.

The data model must be capable of representing the information required by the condominium book without unnecessarily exposing it to other residents.

### UnitRelation

Historical relationship between a Person and a Unit.

Types:

- OWNER — собственик;
- USER — ползвател;
- OCCUPANT — обитател.

Relations have an effective date range. Ownership and occupancy must never be modelled as mutable `owner_id`/`occupant_id` fields on Unit because ownership and residence change over time and historical financial/legal context must remain correct.

For owner relations an optional ownership share can represent co-ownership of the independent unit.

### User

Authentication account linked to a Person. There is no self-registration.

Accounts are created or invited by authorised management users.

Security roles are capabilities, not property relationships:

- `ROLE_USER`
- `ROLE_MANAGER`
- `ROLE_CASHIER`
- `ROLE_CONTROLLER`
- `ROLE_ADMIN`

`OWNER`, `USER` and `OCCUPANT` remain domain relations, never Symfony roles.

## Privacy and access

The application is private, but privacy boundaries still matter.

Residents may see:

- their own obligations and payment history;
- common financial totals and approved common expenses;
- announcements and documents intended for all residents;
- community content intended for neighbours.

Residents must not automatically see:

- another household's debt balance;
- private contact details;
- management-only notes;
- the complete condominium book;
- restricted documents.

Management, cashier and controller access must be separated by voters/permissions according to their actual responsibilities.

## Finance model

Finance is implemented as an auditable ledger-like domain rather than editable balances.

Planned concepts:

- Fund
- FeePolicy
- Charge
- Payment
- PaymentAllocation
- Expense
- BankAccount
- BankTransaction
- Adjustment/Reversal
- MonthlyReport

Published financial operations are not hard-deleted. Corrections are represented explicitly so history remains explainable.

The application never acts as a wallet. Electronic payments go directly to the appropriate condominium bank account through banking/payment infrastructure; Vhod records, matches and reconciles the payment.

## Official communication and meetings

Official notices, meeting invitations, agenda items, attendance, proxies, quorum, votes, resolutions and minutes form a separate domain from the social feed.

An informal poll in the community module is never an official General Assembly vote.

The UI must make this distinction obvious.

## Community module

The private community space is deliberately neighbour-focused rather than a generic social network.

Planned functions:

- chronological feed visible only to authenticated neighbours;
- posts and comments;
- reactions;
- informal polls;
- neighbour events;
- proposals and ideas;
- mutual-help posts;
- internal board for giving away, lending, borrowing or looking for items/services;
- optional post categories such as `QUESTION`, `IDEA`, `HELP`, `EVENT`, `MARKETPLACE`;
- moderation by authorised users;
- report/hide flow;
- edit history for moderation-sensitive content where appropriate.

No public profiles, public indexing, follower counts or engagement mechanics are needed.

Official announcements have their own model and cannot be impersonated by ordinary community posts.

## Maintenance and signals

Signals are structured work items, not social posts.

A signal has category, description, optional attachments, status and history. It can later be connected to a supplier, expense, maintenance asset or repair project.

Building assets can include the lift, entrance door, intercom, electrical installation, roof, fire-safety equipment and other shared infrastructure.

## Documents

Documents are private and access-controlled. They are never stored as publicly guessable files under a web-accessible upload directory.

Planned categories include:

- meeting invitations and minutes;
- house rules;
- invoices and receipts;
- contracts and offers;
- warranties;
- bank statements;
- technical documentation;
- monthly reports.

## Audit

Security-sensitive and legally/financially meaningful changes should be auditable.

The audit trail is not a generic dump of every ORM update. It should capture meaningful actions such as:

- relation changes;
- role/account changes;
- financial postings and reversals;
- official document publication;
- meeting/resolution changes;
- moderation actions.

## UX

The resident home screen should answer the most important questions immediately:

- What do I owe?
- Is everything paid?
- How much is in the repair fund?
- What changed in the entrance?
- Are there open signals or upcoming repairs?
- When is the next meeting?
- What are neighbours discussing?

The management dashboard may expose deeper operational information, but the resident interface must remain simple and mobile-friendly.
