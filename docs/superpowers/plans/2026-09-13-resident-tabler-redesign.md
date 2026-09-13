# Resident Tabler Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert all resident-facing Vhod screens from compatibility-styled custom markup to a coherent, professional Tabler-native UI while preserving every existing workflow, route, permission and business rule.

**Architecture:** Keep the existing Tabler 1.5.1 vertical application shell and Symfony AssetMapper setup. Migrate resident Twig templates to native Tabler/Bootstrap primitives (`page-header`, `card`, `table`, `list-group`, `badge`, `btn`, `form-*`, `alert`, `empty`) and shrink resident reliance on compatibility CSS. No controllers, entities, repositories, routes, schemas or domain services change.

**Tech Stack:** PHP 8.4, Symfony 8.1, Twig 3, AssetMapper/Importmap, Tabler 1.5.1, PHPUnit 12, PHPStan 2.

**Spec:** `docs/superpowers/specs/2026-09-12-resident-tabler-redesign-design.md`

## Global Constraints

- Work only in PR #24 / branch `feat/resident-tabler-redesign`.
- PR #25 must not be created until PR #24 is merged.
- Use Tabler 1.5.1 already pinned in `importmap.php`; add no Node/Vite/Webpack build.
- No controller/entity/repository/schema/business-rule/permission/route changes.
- No dark mode, folded sidebar, layout configurator, new data sources or invented charts.
- Do not commit proprietary Tabler premium assets.
- Prefer native Tabler/Bootstrap markup over new custom CSS.
- Keep PDF/print templates functionally separate from the web redesign unless a shared web dependency requires a minimal correction.
- Preserve all CSRF field names, form field names, button names and route targets used by existing tests.
- Add only structural UI regression assertions; no screenshot/pixel tests.
- Exact-head CI must pass before merge: Composer validation, AssetMapper compile, container lint, Doctrine checks/migrations, PHPUnit and PHPStan.

---

### Task 1: Establish resident UI contracts and polish the dashboard

**Files:**
- Modify: `tests/Controller/DashboardReleaseReadinessTest.php`
- Modify: `tests/Controller/TablerUiFoundationTest.php`
- Modify: `templates/dashboard/index.html.twig`
- Modify: `assets/styles/app.css`

**Interfaces:**
- Consumes: current dashboard variables `person`, `total_balance_cents`, `unit_balances`, `active_signal_count`, and `announcement_unread_count()`.
- Produces: stable resident dashboard containers `data-testid="resident-dashboard-summary"` and `data-testid="resident-dashboard-sections"`; all later resident pages copy the same Tabler page-header/action rhythm.

- [ ] **Step 1: Add failing dashboard structure assertions**

Extend `DashboardReleaseReadinessTest::testDashboardShowsOnlyCurrentResidentsFinancialAndMaintenanceSummary()` with:

```php
self::assertSelectorExists('[data-testid="resident-dashboard-summary"].row.row-cards');
self::assertSelectorCount(4, '[data-testid="resident-dashboard-summary"] > [class*="col-"]');
self::assertSelectorExists('[data-testid="resident-dashboard-sections"] .card');
self::assertSelectorExists('.page-header .page-title');
```

Keep all existing business-data assertions unchanged.

- [ ] **Step 2: Run the focused RED tests**

Run:

```bash
vendor/bin/phpunit tests/Controller/DashboardReleaseReadinessTest.php tests/Controller/TablerUiFoundationTest.php
```

Expected: failure because the new dashboard `data-testid` containers do not exist yet.

- [ ] **Step 3: Rebuild the dashboard with compact Tabler summary cards**

Keep the existing page header and change the first card grid to this structure:

```twig
<div class="row row-deck row-cards mb-3" data-testid="resident-dashboard-summary">
    <div class="col-sm-6 col-xl-3">
        <article class="card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="subheader">Моето салдо</div>
                </div>
                <div class="h1 mb-1">{{ (total_balance_cents / 100)|number_format(2, '.', '') }} €</div>
                <div class="text-secondary small">За активните обекти в профила</div>
            </div>
            <div class="card-footer bg-transparent">
                <a href="{{ path('app_book') }}" class="link-primary">Моята книга</a>
            </div>
        </article>
    </div>
    {# equivalent native Tabler cards for linked units, active signals and unread announcements #}
</div>
```

Use real values only. Keep `data-testid="active-maintenance-count"` on the active signal number. Replace the old fourth KPI “Общи събрания” with unread official announcements so the four cards match the approved spec: balance, units, active signals, unread announcements.

- [ ] **Step 4: Convert dashboard secondary modules to a distinct Tabler section grid**

Use:

```twig
<div class="row row-cards" data-testid="resident-dashboard-sections">
    <div class="col-lg-4">...</div>
    <div class="col-lg-4">...</div>
    <div class="col-lg-4">...</div>
</div>
```

Create cards for “Общи събрания”, “Общност” and “Документи” using `card-header`, `card-title`, `card-body`, `card-footer`, `btn`/plain links. Do not add charts or fake activity counts.

- [ ] **Step 5: Keep CSS additions narrowly product-specific**

In `assets/styles/app.css`, add only small reusable product polish if native utilities are insufficient, for example:

```css
.vhod-dashboard-card .h1 {
    letter-spacing: -.02em;
}

.vhod-readable {
    max-width: 52rem;
}
```

Do not create replacement classes for `card`, `table`, `btn`, `badge`, `form-control` or `page-header`.

- [ ] **Step 6: Run focused tests GREEN**

```bash
vendor/bin/phpunit tests/Controller/DashboardReleaseReadinessTest.php tests/Controller/TablerUiFoundationTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add tests/Controller/DashboardReleaseReadinessTest.php tests/Controller/TablerUiFoundationTest.php templates/dashboard/index.html.twig assets/styles/app.css
git commit -m "feat: polish resident Tabler dashboard"
```

---

### Task 2: Migrate announcements and documents to native Tabler lists/tables

**Files:**
- Modify: `tests/Controller/AnnouncementControllerTest.php`
- Modify: `tests/Controller/DocumentControllerTest.php`
- Modify: `templates/announcements/index.html.twig`
- Modify: `templates/announcements/show.html.twig`
- Modify: `templates/documents/index.html.twig`
- Modify: `assets/styles/app.css`

**Interfaces:**
- Consumes: existing announcement/document variables, routes and CSRF tokens unchanged.
- Produces: `data-testid="announcement-list"`, `data-testid="announcement-detail"`, and `data-testid="document-list"` as stable structural contracts.

- [ ] **Step 1: Add failing resident official-content assertions**

In `AnnouncementControllerTest::testResidentListsPublishedAnnouncementsNewestFirstAndSeesUnreadState()` add:

```php
self::assertSelectorExists('[data-testid="announcement-list"] .list-group-item');
self::assertSelectorExists('.page-header .page-title');
```

In `testPublishedDetailShowsOfficialMetadataDocumentAndViberAction()` add:

```php
self::assertSelectorExists('[data-testid="announcement-detail"].card');
self::assertSelectorExists('[data-testid="announcement-detail"] .card-body');
```

In `DocumentControllerTest::testListSupportsCategoryFilter()` add:

```php
self::assertSelectorExists('[data-testid="document-list"] .table.table-vcenter');
self::assertSelectorExists('select.form-select[name="category"]');
```

- [ ] **Step 2: Run RED**

```bash
vendor/bin/phpunit tests/Controller/AnnouncementControllerTest.php tests/Controller/DocumentControllerTest.php
```

Expected: failures on the new structural selectors only.

- [ ] **Step 3: Replace announcement compatibility wrappers**

`templates/announcements/index.html.twig` must use a standard page header and list group:

```twig
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center">
        <div class="col">
            <div class="page-pretitle">Официална информация</div>
            <h1 class="page-title">Обяви</h1>
            <div class="text-secondary mt-1">Публикувани съобщения от управлението на входа.</div>
        </div>
    </div>
</div>

<div class="card" data-testid="announcement-list">
    <div class="list-group list-group-flush">
        {% for announcement in announcements %}
            <a class="list-group-item list-group-item-action" href="{{ path('app_announcement_show', {id: announcement.id}) }}">
                ...
            </a>
        {% else %}
            ... Tabler `.empty` block ...
        {% endfor %}
    </div>
</div>
```

Keep unread and official badges and existing date/order semantics.

- [ ] **Step 4: Make announcement detail article-like and action-oriented**

Use a readable page header plus one main card:

```twig
<article class="card vhod-readable" data-testid="announcement-detail">
    <div class="card-body">
        <div class="text-secondary mb-3">...</div>
        <div class="lh-lg">{{ announcement.body|nl2br }}</div>
    </div>
</article>
```

Render linked documents as a compact `list-group` with download actions. Keep `announcement_read_submit`, print, PDF, Viber and back routes unchanged; group actions in `.btn-list`.

- [ ] **Step 5: Convert documents to native filter/card-table structure**

Use `form-label`, `form-select`, `btn btn-primary`, a card containing `.table-responsive` + `table table-vcenter card-table`, and a Tabler `.empty` state. Preserve `name="category"` and the existing category filtering semantics.

Representative table shell:

```twig
<div class="card" data-testid="document-list">
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            ... existing document values and download route ...
        </table>
    </div>
</div>
```

- [ ] **Step 6: Run GREEN**

```bash
vendor/bin/phpunit tests/Controller/AnnouncementControllerTest.php tests/Controller/DocumentControllerTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add tests/Controller/AnnouncementControllerTest.php tests/Controller/DocumentControllerTest.php templates/announcements/index.html.twig templates/announcements/show.html.twig templates/documents/index.html.twig assets/styles/app.css
git commit -m "feat: redesign resident official content"
```

---

### Task 3: Redesign resident assemblies and condominium book

**Files:**
- Modify: `tests/Controller/GeneralAssemblyControllerTest.php`
- Modify: `tests/Controller/GeneralAssemblyMinutesControllerTest.php`
- Modify: `tests/Controller/CondominiumBookControllerTest.php`
- Modify: `templates/assemblies/index.html.twig`
- Modify: `templates/assemblies/show.html.twig`
- Modify: `templates/assemblies/minutes.html.twig`
- Modify: `templates/book/index.html.twig`
- Modify: `templates/book/declaration.html.twig`
- Modify: `assets/styles/app.css`

**Interfaces:**
- Consumes: all current assembly/book entity data and routes unchanged.
- Produces: `data-testid="assembly-list"`, `data-testid="assembly-summary"`, `data-testid="assembly-agenda"`, `data-testid="resident-book-summary"`.

- [ ] **Step 1: Add RED structural assertions**

Add to the existing resident assembly list/detail tests:

```php
self::assertSelectorExists('[data-testid="assembly-list"] .card');
self::assertSelectorExists('.page-header .page-title');
```

On assembly detail:

```php
self::assertSelectorExists('[data-testid="assembly-summary"].card');
self::assertSelectorExists('[data-testid="assembly-agenda"] .card');
```

In `CondominiumBookControllerTest::testResidentBookShowsOnlyOwnActiveUnit()` add:

```php
self::assertSelectorExists('[data-testid="resident-book-summary"] .card');
self::assertSelectorExists('.page-header .page-title');
```

- [ ] **Step 2: Run RED**

```bash
vendor/bin/phpunit tests/Controller/GeneralAssemblyControllerTest.php tests/Controller/GeneralAssemblyMinutesControllerTest.php tests/Controller/CondominiumBookControllerTest.php
```

- [ ] **Step 3: Convert assembly list to Tabler status cards**

Use standard page header, `row row-cards` or a single card/list depending on density. Every assembly card must preserve title, scheduled date/timezone, place, status and links. Status must be text plus color; never color-only.

- [ ] **Step 4: Convert assembly detail to summary data grid + agenda cards**

Use:

```twig
<div class="card mb-3" data-testid="assembly-summary">
    <div class="card-body">
        <div class="datagrid">
            <div class="datagrid-item">
                <div class="datagrid-title">Инициатор</div>
                <div class="datagrid-content">{{ assembly.initiatorDisplayName }}</div>
            </div>
            ...
        </div>
    </div>
</div>

<div class="row row-cards" data-testid="assembly-agenda">
    ... one native card per agenda item ...
</div>
```

Represent final/draft resolution text with card sections or `bg-body-tertiary` blocks, not custom `.assembly-resolution-text`. Preserve every numeric resolution value verbatim.

- [ ] **Step 5: Convert minutes to the same official-document grammar**

Use a page header, `.datagrid` summary, native alert for the immutable-history warning, a `.btn-list` for downloads/back and native cards/list groups for corrections. Do not touch PDF generation templates.

- [ ] **Step 6: Convert resident book to cards/data grid/table**

Use `data-testid="resident-book-summary"` around the unit cards, a `datagrid` for floor/area/ideal parts, a `btn-list` for declaration actions and `table table-vcenter card-table` for declaration history.

Keep every declaration URL exactly as it is now.

- [ ] **Step 7: Convert declaration form to Tabler form controls**

Keep all current field names and conditionals. Every visible label/control pair becomes:

```twig
<div class="mb-3">
    <label class="form-label" for="species">Вид животно</label>
    <input class="form-control" id="species" name="species" type="text" required>
</div>
```

Use `form-select`, `form-control`, `form-hint`, `btn btn-primary`, `btn btn-link`/`btn-outline-secondary`. Preserve the submit text `Изпрати декларацията` because the functional test selects it.

- [ ] **Step 8: Run GREEN and commit**

```bash
vendor/bin/phpunit tests/Controller/GeneralAssemblyControllerTest.php tests/Controller/GeneralAssemblyMinutesControllerTest.php tests/Controller/CondominiumBookControllerTest.php
git add tests/Controller/GeneralAssemblyControllerTest.php tests/Controller/GeneralAssemblyMinutesControllerTest.php tests/Controller/CondominiumBookControllerTest.php templates/assemblies templates/book assets/styles/app.css
git commit -m "feat: redesign assemblies and resident book"
```

---

### Task 4: Redesign community as a human-facing Tabler experience

**Files:**
- Modify: `tests/Controller/CommunityControllerTest.php`
- Modify: `templates/community/index.html.twig`
- Modify: `templates/community/new.html.twig`
- Modify: `templates/community/show.html.twig`
- Modify: `assets/styles/app.css`

**Interfaces:**
- Consumes: current post/poll/comment/reaction/report routes, CSRF names and button names.
- Produces: `data-testid="community-feed"`, `data-testid="community-post"`.

- [ ] **Step 1: Add RED assertions without changing workflow assertions**

In the create/read test add:

```php
self::assertSelectorExists('[data-testid="community-feed"] .card');
self::assertSelectorExists('.page-header .page-title');
```

In the interaction test after opening a post add:

```php
self::assertSelectorExists('[data-testid="community-post"].card');
```

- [ ] **Step 2: Run RED**

```bash
vendor/bin/phpunit tests/Controller/CommunityControllerTest.php
```

- [ ] **Step 3: Convert community feed to native cards**

Header must use standard Tabler page header with a right-side `Нова публикация` primary button. Replace `.notice` with `alert alert-info`, filter form with inline/stacking `form-select` + button, and `.panel` post cards with native `.card`.

Feed shell:

```twig
<div class="row row-cards" data-testid="community-feed">
    {% for post in posts %}
        <div class="col-12">
            <article class="card">
                <div class="card-body">...</div>
                <div class="card-footer">...</div>
            </article>
        </div>
    {% else %}
        ... `.empty` with CTA to `app_community_new` ...
    {% endfor %}
</div>
```

Keep community explicitly labelled unofficial.

- [ ] **Step 4: Convert new-post form to a constrained Tabler card form**

Keep exact field names: `type`, `title`, `body`, `starts_at`, `ends_at`, `poll_options`. Keep button text `Публикувай`. Use `.form-control`, `.form-select`, `.form-hint`, `.row g-3` and a max-width content card rather than compatibility `.panel/.columns`.

- [ ] **Step 5: Convert post detail while preserving all mutation selectors**

Wrap the post in:

```twig
<article class="card vhod-readable" data-testid="community-post">...</article>
```

Use native alerts for informal poll/report context, `list-group` or card sections for comments, and native buttons. Preserve button `name` values used by tests exactly: `poll_vote_submit`, `reaction_like` (and other reaction names), `post_report_submit`, `comment_submit`, `comment_report_submit_{id}`.

- [ ] **Step 6: Run GREEN and commit**

```bash
vendor/bin/phpunit tests/Controller/CommunityControllerTest.php
git add tests/Controller/CommunityControllerTest.php templates/community assets/styles/app.css
git commit -m "feat: redesign resident community UI"
```

---

### Task 5: Redesign resident maintenance screens and forms

**Files:**
- Modify: `tests/Controller/MaintenanceControllerTest.php`
- Modify: `templates/maintenance/index.html.twig`
- Modify: `templates/maintenance/new.html.twig`
- Modify: `templates/maintenance/show.html.twig`
- Modify: `assets/styles/app.css`

**Interfaces:**
- Consumes: current maintenance routes, CSRF tokens, upload field and test-selected button names.
- Produces: `data-testid="maintenance-list"`, `data-testid="maintenance-detail"`.

- [ ] **Step 1: Add RED assertions**

After opening the new-signal form in `testResidentCanCreateAndViewOwnSignal()` add:

```php
self::assertSelectorExists('.page-header .page-title');
self::assertSelectorExists('select.form-select[name="category"]');
self::assertSelectorExists('input.form-control[name="title"]');
```

After opening the created detail add:

```php
self::assertSelectorExists('[data-testid="maintenance-detail"] .card');
```

Add one GET `/maintenance` assertion in the same test or a focused new test:

```php
$this->client->request('GET', '/maintenance');
self::assertResponseIsSuccessful();
self::assertSelectorExists('[data-testid="maintenance-list"]');
```

- [ ] **Step 2: Run RED**

```bash
vendor/bin/phpunit tests/Controller/MaintenanceControllerTest.php
```

- [ ] **Step 3: Convert signal list to card-table with status badges**

Use standard page header with `Нов сигнал` primary action. Use a responsive `table table-vcenter card-table` inside a card with `data-testid="maintenance-list"`. Keep title first, location secondary, then category, priority, status and created date. Apply semantic badge classes but always render the text label.

- [ ] **Step 4: Convert new signal form to native controls**

Keep exact names and IDs. Use `form-label`, `form-select`, `form-control`, and `form-hint`; keep `name="signal_submit"` on the primary submit button.

- [ ] **Step 5: Convert signal detail into summary + attachments + history**

Use a wrapper with `data-testid="maintenance-detail"`, a two-column responsive card layout for details/attachments, and a separate history card table. Keep `attachment_submit`, upload `name="attachment"`, accepted MIME list and all existing routes unchanged.

Use native `.list-group` for attachments:

```twig
<div class="list-group list-group-flush">
    {% for attachment in attachments %}
        <a class="list-group-item list-group-item-action" href="{{ path('app_maintenance_attachment_download', {id: attachment.id}) }}">...</a>
    {% endfor %}
</div>
```

- [ ] **Step 6: Run GREEN and commit**

```bash
vendor/bin/phpunit tests/Controller/MaintenanceControllerTest.php
git add tests/Controller/MaintenanceControllerTest.php templates/maintenance assets/styles/app.css
git commit -m "feat: redesign resident maintenance UI"
```

---

### Task 6: Remove resident compatibility styling and verify responsive/accessibility contracts

**Files:**
- Modify: `assets/styles/app.css`
- Modify as needed only for resident markup consistency: `templates/dashboard/index.html.twig`, `templates/announcements/*.html.twig`, `templates/documents/index.html.twig`, `templates/assemblies/{index,show,minutes}.html.twig`, `templates/book/*.html.twig`, `templates/community/*.html.twig`, `templates/maintenance/*.html.twig`
- Modify: `tests/Controller/TablerUiFoundationTest.php`

**Interfaces:**
- Consumes: all migrated resident markup from Tasks 1-5.
- Produces: resident pages no longer depend on `.hero`, `.panel`, `.button-link`, `.notice`, `.filter-form`, `.official-*`, `.assembly-*` compatibility presentation classes where the corresponding resident template has been migrated.

- [ ] **Step 1: Add a focused source-level regression test for resident legacy classes**

Add to `TablerUiFoundationTest` a test that scans only resident web templates and rejects the key compatibility classes. Use `file_get_contents()` against explicit files so management templates are not affected:

```php
public function testResidentTemplatesNoLongerUseLegacyCompatibilityComponents(): void
{
    $paths = [
        'templates/dashboard/index.html.twig',
        'templates/announcements/index.html.twig',
        'templates/announcements/show.html.twig',
        'templates/documents/index.html.twig',
        'templates/assemblies/index.html.twig',
        'templates/assemblies/show.html.twig',
        'templates/assemblies/minutes.html.twig',
        'templates/book/index.html.twig',
        'templates/book/declaration.html.twig',
        'templates/community/index.html.twig',
        'templates/community/new.html.twig',
        'templates/community/show.html.twig',
        'templates/maintenance/index.html.twig',
        'templates/maintenance/new.html.twig',
        'templates/maintenance/show.html.twig',
    ];

    foreach ($paths as $path) {
        $content = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        self::assertIsString($content);
        foreach (['class="hero', 'class="panel', 'button-link', 'notice-info', 'notice-soft'] as $legacy) {
            self::assertStringNotContainsString($legacy, $content, $path.' still uses '.$legacy);
        }
    }
}
```

Do not include print/PDF or management templates in this assertion.

- [ ] **Step 2: Run RED if any migrated template still has compatibility markup**

```bash
vendor/bin/phpunit tests/Controller/TablerUiFoundationTest.php
```

Expected: any remaining resident compatibility use is reported by exact filename.

- [ ] **Step 3: Remove only CSS rules no longer required by any current template**

Before deleting a CSS rule, search the repository. Keep compatibility rules still used by management screens for PR #25/#26. Remove resident-only rules that have no remaining references. Preserve `.nav-section-title`, `.navbar-footer`, `.vhod-sidebar-user` and the very small Vhod-specific readable/dashboard helpers still in use.

- [ ] **Step 4: Validate Twig and assets**

Run:

```bash
php bin/console lint:twig templates
php bin/console asset-map:compile
vendor/bin/phpunit tests/Controller/TablerUiFoundationTest.php
```

Expected: all PASS.

- [ ] **Step 5: Run the complete resident-focused controller set**

```bash
vendor/bin/phpunit \
  tests/Controller/DashboardReleaseReadinessTest.php \
  tests/Controller/AnnouncementControllerTest.php \
  tests/Controller/DocumentControllerTest.php \
  tests/Controller/GeneralAssemblyControllerTest.php \
  tests/Controller/GeneralAssemblyMinutesControllerTest.php \
  tests/Controller/CondominiumBookControllerTest.php \
  tests/Controller/CommunityControllerTest.php \
  tests/Controller/MaintenanceControllerTest.php \
  tests/Controller/TablerUiFoundationTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit cleanup**

```bash
git add assets/styles/app.css templates tests/Controller/TablerUiFoundationTest.php
git commit -m "refactor: remove resident UI compatibility markup"
```

---

### Task 7: Exact-head release verification for PR #24

**Files:**
- No feature files should change unless verification exposes a regression.
- Update PR #24 description/checklist after final verification.

**Interfaces:**
- Consumes: exact PR head after Tasks 1-6.
- Produces: merge-ready PR #24 only; no #25 branch yet.

- [ ] **Step 1: Run the exact CI command set locally when available**

```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
php bin/console asset-map:compile
php bin/console lint:container
php bin/console doctrine:schema:validate --skip-sync --env=test
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
```

If local GitHub network access is unavailable, do not claim these passed locally; rely on GitHub Actions exact-head run.

- [ ] **Step 2: Review PR scope**

Use the PR diff to confirm there are no changes under `src/`, `migrations/`, database config, routing or security/domain logic. Expected feature diff is Twig, CSS, resident controller tests, design/plan docs only.

- [ ] **Step 3: Wait for exact-head GitHub Actions result and inspect any failure**

The run must include the repository CI gates from `.github/workflows/ci.yml`: Composer validation/install, AssetMapper compile, container lint, Doctrine validation/migrations, PHPUnit and PHPStan.

- [ ] **Step 4: Make PR ready only after all gates are green**

Update PR #24 description with the final migrated areas and verification result. Do not merge automatically unless explicitly requested.

- [ ] **Step 5: After user merges #24, create #25 from the new `main` only**

Do not pre-create the management UI branch. Fetch `main` after merge, verify the #24 merge commit is present, then create the next branch from that exact ref.
