# Resident Tabler Redesign Design

Date: 2026-09-12

## Goal

Redesign the resident-facing Vhod interface so it feels like a polished, cohesive product built natively with Tabler rather than a custom UI styled to resemble Tabler.

The visual direction is intentionally **Tabler-first with a small Vhod identity layer**: roughly 90% native Tabler layout/components and 10% product-specific character through copy, content hierarchy, icon choices, and restrained accents.

This redesign is UI-only. It must not change domain behavior, controllers, entities, permissions, routes, persistence, or financial/assembly rules.

## Delivery model

UI work is delivered sequentially to avoid stacked branches and merge conflicts:

1. PR #24 — Resident UI
2. PR #25 — Management UI, created only after PR #24 is merged
3. PR #26 — Final compatibility CSS cleanup and polish, created only after PR #25 is merged

Every new branch must be created from the then-current `main` after the previous PR has merged.

## Design principles

### 1. Native Tabler before custom CSS

Prefer Tabler/Bootstrap markup and utilities for:

- page headers
- cards
- buttons
- badges
- tables and list groups
- forms
- alerts
- avatars and status indicators
- empty states
- responsive grids
- spacing and typography

Do not introduce a second component system in `app.css`.

### 2. Vhod identity stays subtle

Vhod should feel warmer than a generic admin panel without becoming decorative or themed heavily.

Use:

- friendly Bulgarian copy
- clear resident-oriented wording
- restrained primary/status accents
- Tabler Icons for semantic cues
- meaningful empty-state messages
- stronger hierarchy around important household/condominium information

Avoid:

- decorative gradients without meaning
- excessive rounded cards
- unnecessary shadows
- invented charts or metrics
- novelty illustrations where a simple state is clearer

### 3. Content over decoration

The interface should make resident tasks easier to scan and act on. Real information takes priority over dashboard decoration.

No charts will be added unless real time-series data already exists and a chart improves comprehension.

## Scope of PR #24

PR #24 covers resident-facing screens only.

Primary areas:

- dashboard
- announcements
- documents
- resident general assemblies
- condominium book / resident book views
- community
- resident maintenance/signals
- authentication presentation where required for consistency
- shared resident page-header / empty-state / action patterns

Management screens remain functionally and visually usable but are not comprehensively redesigned in this PR.

## Application shell

Keep the current Tabler vertical application shell introduced previously.

Refine rather than replace it:

- preserve role-aware navigation and current permissions
- keep responsive collapse behavior
- improve iconography and active-state clarity where useful
- retain current user/logout area
- keep content in Tabler page/page-wrapper/container conventions

Do not introduce folded sidebar or dark mode in PR #24. They are available in Tabler but add testing surface without helping the immediate resident redesign.

## Page structure

Resident pages should converge on a standard pattern:

```text
page header
  pretitle / context when useful
  title
  short secondary description when useful
  primary actions on the right

page content
  cards / tables / list groups / forms
  contextual empty states
```

Avoid custom `.hero` wrappers once a page is migrated.

## Dashboard

The dashboard becomes a resident command center, not a showcase page.

### Top area

Use a Tabler page header with:

- contextual pretitle such as “Нашият вход”
- personal greeting when a person is linked
- short, practical supporting text

### Primary summary cards

Use compact KPI-style Tabler cards for existing real values only:

- current resident balance
- linked units/objects
- active maintenance signals
- unread official announcements

Each card should have:

- semantic Tabler icon
- concise label
- large value/status
- minimal secondary explanation
- link only when useful

### Secondary content

Use larger cards/list sections for:

- official announcements
- upcoming/recent general assembly information where data exists
- community activity
- documents / resident records where meaningful

Do not force all secondary modules into identical cards if a list-group or compact table is more appropriate.

## Announcements

Announcements should feel official and scannable.

List view:

- standard page header
- unread/read status visible but restrained
- publication date and metadata in secondary text
- title as primary scan target
- native Tabler cards or list groups
- clear unread badge

Detail view:

- article-like readable width
- metadata above content
- actions grouped consistently
- avoid oversized decorative borders

Empty state:

- Tabler-style empty state
- short explanation
- no CTA if the resident cannot create announcements

## Documents

Document screens should prioritize file metadata and actions.

Use:

- compact cards/list rows or table depending on density
- document/file icon
- document type/status badge where relevant
- date and metadata as secondary text
- download/view actions aligned consistently

Do not render documents as large content cards unless the document itself has substantial descriptive content.

## General assemblies

Resident assembly pages should communicate legal/official information clearly without resembling an internal admin workbench.

Use:

- page header with assembly title/status
- summary card/data grid for date, place, quorum/status metadata
- agenda as ordered list/cards with clear numbering
- resolutions and results separated visually
- semantic badges for status
- readable minutes/finalized content

Preserve every existing permission and workflow rule.

## Condominium book / resident records

Use Tabler data-grid and card patterns for resident identity/property information.

Prefer label/value layouts over generic panels.

Financial or ownership values must remain visually precise and not be obscured by decorative presentation.

## Community

Community should feel more human than management pages while remaining consistent with Tabler.

Posts:

- avatar/initial + author identity
- time/meta in secondary text
- body with comfortable reading width
- reactions/actions in a compact footer
- event/poll content expressed with native cards/list groups/progress where appropriate

Avoid making community posts look like admin data tables.

## Maintenance

Signal list:

- clear status badges
- subject/title first
- date and update metadata secondary
- priority/status semantic color only where meaningful

Signal detail:

- summary/status area
- conversation/history separated from primary issue description
- attachments displayed as file items rather than raw links where possible

## Forms

Resident forms should rely on Symfony's Tabler-compatible form theme and native classes.

Standards:

- explicit labels
- help text directly below controls
- validation near the field
- primary action first
- cancel/back as secondary action
- destructive actions separated visually
- reasonable form width; avoid full-width controls across huge desktop canvases when unnecessary

## Empty states

Use Tabler's empty-state pattern consistently.

An empty state contains only what helps:

- optional free/open Tabler icon or illustration
- short title
- one sentence explaining the state
- CTA only when the current resident can actually resolve the empty state

Premium/proprietary illustrations must not be committed to the public repository unless redistribution rights are explicitly confirmed.

## Icons

Use Tabler Icons consistently for navigation and key actions/statuses.

Icons supplement text; they do not replace critical labels.

Do not add a large icon dependency outside the existing Tabler ecosystem.

## CSS strategy

During PR #24:

- migrate touched resident templates away from `.panel`, `.hero`, `.button-link`, `.notice`, custom `.grid` and similar compatibility abstractions where practical
- leave compatibility rules required by management screens until their migration
- add only narrowly scoped Vhod-specific CSS when native Tabler/Bootstrap utilities cannot express the desired layout cleanly

Full compatibility CSS deletion belongs to PR #26 after both resident and management migrations are complete.

## Responsive behavior

Every migrated screen must work at:

- mobile phone width
- tablet width
- standard desktop

Important rules:

- actions wrap or stack predictably
- tables use responsive wrappers or alternate compact presentation where necessary
- no horizontal overflow from metadata/action groups
- sidebar collapse remains usable
- card grids collapse according to content, not arbitrary fixed columns

## Accessibility

Maintain or improve:

- semantic headings
- visible keyboard focus
- labeled controls
- adequate contrast
- `aria-current` for active navigation
- meaningful button/link text
- no color-only status meaning

## Testing and regression protection

Do not write pixel/screenshot tests.

Add focused structural regression tests only where stable UI contracts matter, for example:

- primary resident pages render successfully
- expected page headers/actions remain present
- role/permission-specific navigation remains correct
- key forms continue rendering/submit behavior unchanged

The complete existing PHPUnit and PHPStan suite must pass on the exact PR head before merge.

AssetMapper compilation and existing CI gates remain mandatory.

## Out of scope for PR #24

- management redesign
- dark mode
- folded-sidebar preference UI
- layout customization panel
- new charts/data sources
- controller/domain refactors
- new business features
- proprietary Tabler premium assets
- JavaScript-heavy datatable conversion unless already required by existing behavior

## Success criteria

PR #24 is successful when:

1. Resident-facing screens visibly belong to one coherent Tabler-native product.
2. The UI no longer looks like legacy custom panels inside a Tabler shell.
3. Resident workflows and permissions behave exactly as before.
4. Migrated templates rely primarily on Tabler/Bootstrap primitives rather than compatibility CSS.
5. Mobile and desktop layouts remain usable and clear.
6. No new business scope is introduced.
7. Exact-head CI is green before merge.

## Reference direction

The design follows the current Tabler 1.5.1 demo and component system, particularly its vertical layout, page headers, cards, data grids, forms, lists, badges, avatars and empty states.

References:

- https://preview.tabler.io/
- https://tabler.io/admin-template
- https://tabler.io/admin-template/features
