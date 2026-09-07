# Vhod roadmap

The roadmap is intentionally split into vertical slices. Each slice must leave the application in a working, testable state.

## 1. Foundation and identity

- Symfony 8.1 application skeleton
- PHP 8.4 baseline
- Doctrine ORM and MariaDB configuration
- Twig + AssetMapper
- authentication without public registration
- Unit
- Person
- UnitRelation with historical date ranges
- User linked to Person
- role model
- first database migration
- initial dashboard shell
- PHPUnit and PHPStan CI

## 2. Condominium book

- fields required by the current official MRRB template
- controlled data-entry workflow
- electronic change declaration workflow
- owner access to own data
- manager/controller access according to legal requirements
- change history
- export compatible with the official form

## 3. Fees and funds

- Fund
- FeePolicy with effective dates
- monthly charge generation
- management/maintenance charges
- Repair and Renovation Fund
- historical rules preserved after policy changes
- exemptions/special cases represented explicitly
- idempotent monthly generation

## 4. Payments and reconciliation

- cash and bank payments
- payment allocation to charges
- unique payment references
- bank statement import
- idempotent transaction import
- automatic matching and manual reconciliation queue
- personal statement for each household

## 5. Expenses and reports

- Expense and supporting documents
- categories and fund allocation
- approvals/decision references where applicable
- monthly income/expense report compatible with the official MRRB form
- common financial dashboard
- budget vs actual
- no public debtor list

## 6. Community

- neighbours-only feed
- posts
- comments
- reactions
- informal polls
- events
- ideas and proposals
- mutual-help posts
- internal give/lend/borrow/find board
- moderation/reporting
- clear visual separation from official notices and votes

## 7. Signals and maintenance

- structured signals
- attachments/photos
- status history
- assignment
- building assets
- suppliers and contracts
- maintenance events
- warranty/inspection reminders

## 8. Documents and announcements

- private document storage
- permissions
- document categories
- official announcements
- resident notifications
- printable/PDF outputs
- optional Viber share links for convenience

## 9. General Assembly

- invitation workflow
- agenda and draft resolutions
- legal deadline checklist
- attendance
- proxies
- represented ideal parts
- quorum calculation
- majority rules by decision type
- resolutions
- minutes
- formal voting kept separate from community polls

## 10. Compliance and hardening

- condominium registry/identifier data
- management mandate reminders
- monthly report reminder
- annual control/cash audit reminder
- contract/inspection expiry reminders
- access-control review
- encrypted backups
- restore test procedure
- audit log review
- security headers and rate limiting
- production monitoring

## First release target

A useful first release should include slices 1–5 plus basic announcements and signals. Community can be developed in parallel after the identity model is stable because it uses the same authenticated Person/User foundation.
