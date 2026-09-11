# Tabler UI Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Vhod's hand-built application shell with a responsive Tabler 1.5.1 foundation while preserving all routes, role visibility and business behavior.

**Architecture:** Keep Twig server-side rendering and AssetMapper/Importmap. Register `@tabler/core` and its compiled CSS in `importmap.php`, import them from the existing `assets/app.js`, and use Tabler's vertical navbar/page/card/form primitives in the global shell, login and dashboard. Keep Vhod-specific domain CSS only where markup carries domain meaning.

**Tech Stack:** PHP 8.4, Symfony 8.1, Twig, AssetMapper, Importmap, Turbo, Stimulus, Tabler 1.5.1, PHPUnit, PHPStan.

**Spec:** `docs/superpowers/specs/2026-09-11-tabler-ui-foundation-design.md`

## Global Constraints

- PHP >= 8.4.
- Symfony 8.1.
- Tabler 1.5.1.
- Twig server-side rendering remains primary.
- AssetMapper/Importmap remain the asset pipeline; no Node production build.
- Existing route names, `ROLE_*` checks, CSRF and domain behavior remain unchanged.
- No database migration.
- No proprietary Tabler files or premium illustrations.
- `php bin/console asset-map:compile`, PHPUnit and PHPStan must pass before merge.

---

### Task 1: Register Tabler through AssetMapper

**Files:**
- Modify: `importmap.php`
- Modify: `assets/app.js`
- Modify: `composer.json`

**Interfaces:**
- Consumes: Symfony AssetMapper's package installation from `importmap.php`.
- Produces: locally downloaded `@tabler/core` CSS/JS during `composer install` via `importmap:install`.

- [ ] **Step 1: Register the exact Tabler packages**

Add these import-map entries:

```php
'@tabler/core' => [
    'version' => '1.5.1',
],
'@tabler/core/dist/css/tabler.min.css' => [
    'version' => '1.5.1',
    'type' => 'css',
],
```

- [ ] **Step 2: Load Tabler before Vhod overrides**

Make `assets/app.js` import Tabler first and local overrides last:

```js
import '@tabler/core/dist/css/tabler.min.css';
import '@tabler/core';
import './styles/app.css';
```

- [ ] **Step 3: Make fresh installs deterministic**

Add the standard AssetMapper auto-script to `composer.json`:

```json
"auto-scripts": {
    "cache:clear": "symfony-cmd",
    "importmap:install": "symfony-cmd"
}
```

- [ ] **Step 4: Commit**

```bash
git add importmap.php assets/app.js composer.json
git commit -m "feat: wire Tabler through AssetMapper"
```

### Task 2: Define UI shell behavior with focused functional tests

**Files:**
- Create: `tests/Controller/TablerUiFoundationTest.php`

**Interfaces:**
- Consumes: existing `/`, `/login`, role checks and test database setup.
- Produces: regression coverage for the Tabler shell, mobile sidebar toggle, resident navigation and admin-only navigation.

- [ ] **Step 1: Add a failing login-shell test**

Create a `WebTestCase` test that requests `/login` and asserts the existing login fields plus the new Tabler auth primitives:

```php
self::assertResponseIsSuccessful();
self::assertSelectorExists('.page-center .card');
self::assertSelectorExists('input.form-control[name="_username"]');
self::assertSelectorExists('input.form-control[name="_password"]');
self::assertSelectorExists('button.btn.btn-primary[type="submit"]');
```

- [ ] **Step 2: Add a failing authenticated-shell test**

Create schema, persist a normal `User`, log in and request `/`. Assert:

```php
self::assertSelectorExists('aside.navbar.navbar-vertical');
self::assertSelectorExists('button.navbar-toggler[data-bs-target="#sidebar-menu"]');
self::assertSelectorExists('.page-wrapper');
self::assertSelectorExists('a[href="/community"]');
self::assertSelectorExists('a[href="/maintenance"]');
self::assertSelectorNotExists('a[href="/management/setup"]');
```

- [ ] **Step 3: Add an admin navigation test**

Persist a user with `ROLE_ADMIN`, log in and assert the existing admin destinations stay visible:

```php
self::assertSelectorExists('a[href="/management/setup"]');
self::assertSelectorExists('a[href="/management/audit"]');
```

- [ ] **Step 4: Verify RED in CI/focused local run when available**

Run:

```bash
vendor/bin/phpunit tests/Controller/TablerUiFoundationTest.php
```

Expected before Task 3: failures for missing Tabler shell/form classes.

- [ ] **Step 5: Commit**

```bash
git add tests/Controller/TablerUiFoundationTest.php
git commit -m "test: define Tabler UI shell"
```

### Task 3: Implement the Tabler shell, login and dashboard

**Files:**
- Modify: `templates/base.html.twig`
- Modify: `templates/security/login.html.twig`
- Modify: `templates/dashboard/index.html.twig`

**Interfaces:**
- Consumes: all existing route names, `announcement_unread_count()`, `app.user`, `is_granted()` and dashboard variables.
- Produces: one responsive vertical application shell used by authenticated pages and one compact unauthenticated auth shell.

- [ ] **Step 1: Replace the authenticated topbar with Tabler's vertical shell**

Use this outer structure while preserving the existing permission expressions exactly:

```twig
<div class="page">
    <aside class="navbar navbar-vertical navbar-expand-lg" aria-label="Основна навигация">
        <div class="container-fluid">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="Отвори навигацията">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="navbar-brand navbar-brand-autodark">
                <a href="{{ path('app_dashboard') }}">Vhod</a>
            </div>
            <div class="collapse navbar-collapse" id="sidebar-menu">
                <ul class="navbar-nav pt-lg-3">
                    {# existing routes grouped into Main / Community / Condominium / Management / System #}
                </ul>
            </div>
            <div class="navbar-footer">
                {# signed-in identity and logout #}
            </div>
        </div>
    </aside>
    <div class="page-wrapper">
        <main class="page-body">
            <div class="container-xl">
                {# flashes + body block #}
            </div>
        </main>
    </div>
</div>
```

Use `app.request.attributes.get('_route')` only to mark the matching navigation link active; do not alter authorization.

- [ ] **Step 2: Keep unauthenticated pages out of the application sidebar**

Render the login body in a `page page-center` / `container-tight` shell when `app.user` is absent.

- [ ] **Step 3: Restyle login with native form classes**

Preserve field names and CSRF token, changing presentation only:

```twig
<div class="card card-md">
    <div class="card-body">
        <form method="post" class="mt-4">
            <div class="mb-3">
                <label class="form-label" for="username">Имейл</label>
                <input class="form-control" id="username" type="email" name="_username" ...>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Парола</label>
                <input class="form-control" id="password" type="password" name="_password" ...>
            </div>
            <button class="btn btn-primary w-100" type="submit">Вход</button>
        </form>
    </div>
</div>
```

- [ ] **Step 4: Recompose dashboard with Tabler rows/cards**

Keep all existing variables and links. Replace the custom `.grid`, `.columns`, `.panel` and generic button presentation with `row`, responsive `col-*`, `card`, `card-body`, `subheader`, `h2`/`h3`, `btn` and badge utilities. Preserve `data-testid="active-maintenance-count"`.

- [ ] **Step 5: Verify GREEN**

Run:

```bash
vendor/bin/phpunit tests/Controller/TablerUiFoundationTest.php tests/Controller/DashboardReleaseReadinessTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add templates/base.html.twig templates/security/login.html.twig templates/dashboard/index.html.twig
git commit -m "feat: add responsive Tabler application shell"
```

### Task 4: Reduce generic CSS duplication and verify the whole branch

**Files:**
- Modify: `assets/styles/app.css`
- Modify: `config/packages/twig.yaml`
- Modify if needed after failures: existing Twig templates only for compatibility class additions; do not change controllers/entities.

**Interfaces:**
- Consumes: Tabler CSS variables and Bootstrap 5 form rendering.
- Produces: Vhod-specific overrides/domain styles without a parallel generic component framework.

- [ ] **Step 1: Enable Bootstrap 5 Symfony form rendering**

Configure:

```yaml
twig:
    file_name_pattern: '*.twig'
    form_themes: ['bootstrap_5_layout.html.twig']
```

Keep the existing `when@test` strict-variable configuration.

- [ ] **Step 2: Remove CSS superseded by Tabler**

Delete or stop owning generic definitions for the global topbar, `.container`, basic `.card`, generic `.badge`, raw `input/select/textarea`, generic buttons and generic alerts after their Twig markup uses Tabler classes.

Retain narrowly scoped Vhod styles such as:

```css
.nav-section-title { /* only Vhod group spacing/label behavior if needed */ }
.community-body { line-height: 1.7; }
.official-card { /* domain emphasis layered on Tabler card */ }
.assembly-facts { /* domain data layout */ }
.assembly-result { /* General Assembly result presentation */ }
```

Compatibility selectors such as `.button-link` may remain only where unconverted domain templates still need them in this foundation PR; style them with Tabler tokens and remove them in later template-specific cleanup rather than introducing new generic abstractions.

- [ ] **Step 3: Verify AssetMapper installation/compilation**

Run:

```bash
php bin/console importmap:install
php bin/console asset-map:compile
```

Expected: Tabler 1.5.1 assets install locally and compile without missing asset/controller errors.

- [ ] **Step 4: Run the full quality gate**

```bash
php bin/console lint:container
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
```

Expected: all green.

- [ ] **Step 5: Manual responsive acceptance**

At desktop width, verify the sidebar is visible and grouped. At mobile width, verify the toggler opens/closes `#sidebar-menu`, no horizontal nav overflow occurs, and login/dashboard remain usable.

- [ ] **Step 6: Commit and open the PR**

```bash
git add assets/styles/app.css config/packages/twig.yaml templates tests importmap.php assets/app.js composer.json
git commit -m "refactor: align shared UI with Tabler"
```

Open a PR from `feat/tabler-ui-foundation` to `main`, keep it draft until exact-head CI is green, and describe the change as UI-only with no domain/schema changes.
