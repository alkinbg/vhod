# Tabler UI Foundation Design

## Goal

Replace the current hand-built visual shell with a maintainable Tabler-based interface while preserving all existing Symfony routes, permissions, business workflows, Turbo/Stimulus behavior and server-side rendering.

## Scope

This PR is a UI foundation refactor only. It must not change domain behavior, controllers, entities, authorization semantics, CSRF protection, finance rules, General Assembly rules, audit behavior or persistence.

The work covers:

- global authenticated application shell;
- responsive vertical sidebar navigation;
- dashboard presentation;
- login/authentication presentation;
- shared cards, panels, badges, alerts, forms, buttons, tables and empty states;
- responsive/mobile navigation behavior;
- reduction of custom CSS to Vhod-specific overrides.

Premium/proprietary Tabler assets and illustrations are intentionally excluded from this PR. They can be added later from the user's licensed files for selected empty states, auth screens and error pages.

## Framework and Asset Strategy

Use Tabler **1.5.1** as the target UI version. Tabler 1.5 includes Bootstrap 5.3.8 inside `@tabler/core`, so no separate Bootstrap dependency is required.

The project remains Symfony 8.1 with AssetMapper, Importmap, Twig, Stimulus and Turbo. Do not introduce Webpack Encore, Vite, React, Vue or a Node-based production build pipeline merely for this refactor.

Prefer locally managed frontend assets through Symfony's existing asset/importmap setup rather than runtime CDN dependencies. Exact package wiring should keep `php bin/console asset-map:compile` working, as established by PR #21.

## Application Shell

Authenticated screens use Tabler's page shell with a vertical sidebar.

The sidebar contains these logical groups while preserving the existing route visibility rules:

### Main

- Начало

### Community

- Общност
- Поддръжка

### Condominium

- Обяви, including the existing unread count badge
- Общи събрания
- Документи
- Моята книга

### Management

Items are displayed only under the same current `ROLE_*` checks:

- Финанси
- Финансови операции
- Управление на ОС
- Съответствие
- Модерация
- Официални обяви
- Документи · управление

### System

Admin-only items:

- Настройка
- Audit

The signed-in user's identity and logout action belong in the sidebar footer/user area rather than in the main navigation list.

Navigation must remain usable on mobile via Tabler's responsive navbar/sidebar behavior. Desktop may use the standard vertical layout; folded-sidebar behavior may be enabled only if it does not add custom state management or regress Turbo navigation.

## Shared UI Components

Replace generic custom presentation classes with Tabler semantics where practical:

- `.card`, `.card-header`, `.card-body`, `.card-footer` for content containers;
- Tabler/Bootstrap grid utilities for dashboards and multi-column layouts;
- `.btn` variants for actions;
- `.badge` variants for states and counters;
- `.alert` variants for flash messages and notices;
- `.form-control`, `.form-select`, validation classes and standard form layout;
- responsive `.table` patterns for list screens;
- Tabler empty-state patterns for no-data screens.

Do not blindly rewrite Vhod-specific components whose current markup carries domain meaning. Assembly summaries, official-document metadata, community posts and similar domain views may keep dedicated semantic classes layered on top of Tabler primitives.

## Dashboard

The existing dashboard data and controller contract stay unchanged. Recompose its current metrics and sections using Tabler cards and responsive grid utilities.

The dashboard should read as a residential-management application, not as a generic analytics SaaS dashboard. Avoid decorative charts or statistics that do not already exist in the application data.

## Authentication

Restyle the existing login page using a compact Tabler auth/card layout. Do not add registration, forgot-password, social login or other authentication flows that do not already exist.

## CSS Strategy

`assets/styles/app.css` must stop acting as a second UI framework.

After the migration it should contain only:

- application-specific spacing or responsive fixes not provided cleanly by Tabler;
- Vhod domain components such as community content, General Assembly result layouts and official-document presentation;
- narrowly scoped compatibility overrides required by existing Twig markup.

Remove generic custom definitions that duplicate Tabler cards, buttons, badges, form controls, alerts, grid containers and basic layout behavior once the equivalent markup has migrated.

## Accessibility and Responsiveness

- Preserve semantic navigation and heading structure.
- Keep visible focus states provided by Tabler/Bootstrap.
- Navigation must remain keyboard accessible.
- Forms retain their labels and validation errors.
- Mobile layout must remain usable without horizontal navigation overflow.
- Do not encode important state using color alone.

## Compatibility Constraints

- PHP >= 8.4.
- Symfony 8.1.
- Twig server-side rendering remains primary.
- AssetMapper/Importmap remain the asset pipeline.
- Turbo/Stimulus remain enabled.
- `php bin/console asset-map:compile` must pass.
- Existing role checks and route names are preserved.
- No schema migration is expected.
- No proprietary Tabler files are committed in this PR.

## Testing and Verification

The PR must protect behavior rather than assert cosmetic implementation details excessively.

Verification should include:

- existing functional/controller test suite;
- focused assertions that authenticated navigation still exposes the same role-appropriate links;
- login page still renders and submits through the existing security flow;
- dashboard still renders its real data;
- `php bin/console asset-map:compile` succeeds;
- PHPUnit and PHPStan remain green;
- manual responsive smoke test for desktop and mobile widths after local checkout.

## Non-goals

This PR does not add:

- new business functionality;
- new dashboard metrics;
- charts without existing data requirements;
- dark mode persistence/customization UI;
- user-selectable themes;
- premium illustrations;
- broad Twig component abstraction merely for stylistic purity;
- a new frontend build system.

## Success Criteria

The application should look and behave like one coherent Tabler-based product, with a clear responsive sidebar and consistent controls, while existing domain workflows and permissions remain unchanged. The amount of generic custom CSS should decrease substantially rather than grow alongside Tabler.
