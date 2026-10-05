# AGENTS.md

Guidance for AI coding agents (and humans) working on **Selective Entity Sync**.

## What this project is

A WordPress plugin that moves **selected content** from one site to another, typically Staging → Production. It never runs a full or table-level database migration, so live orders, users and form entries on the target are never overwritten.

- Staging **exports** an entity manifest: a `.zip` holding `manifest.json` plus the media files.
- Production **imports** it. Entities are matched by a stable UUID, and every relational ID is **remapped**: post parents, featured images, term hierarchy, and image/gallery IDs inside block content.

### Scope (free plugin, v0.x)

- In scope:
  - Posts, pages and custom post types (with post meta and parent relations).
  - Taxonomies and terms (hierarchy and term meta).
  - Media attachments (files bundled).
  - Admin UI, REST API and WP-CLI.
- Out of scope for now: ACF, direct site-to-site push, users, WooCommerce orders, and anything "Pro". Don't add these to core. Design hooks so an add-on could add them.

## Commands

| Task | Command |
|---|---|
| Install deps | `composer install && npm install` |
| Start / stop WordPress | `npm run wp-env start` / `npm run wp-env stop` (dev: http://localhost:8898, tests: http://localhost:8899, `admin` / `password`) |
| Build JS / watch | `npm run build` / `npm run start` |
| PHP lint / autofix | `composer lint` / `composer lint:fix` |
| PHP static analysis | `composer analyse` |
| JS + CSS lint | `npm run lint` |
| PHP unit tests (no WP) | `composer test:unit` |
| PHP integration tests (wp-env) | `npm run test:php` |
| E2E tests (Playwright, wp-env) | `npm run test:e2e` |
| WP-CLI | `npm run wp-env run cli wp <command>` |
| Regenerate translations | `npm run makepot` then `npm run makejson` |
| Release zip | `composer install --no-dev -o && npm run build && npm run plugin-zip` |

## Layout

```
selective-entity-sync.php   Bootstrap only: header, constants, env checks, autoload, boot Plugin.
uninstall.php               Removes plugin settings/temp files. Never removes imported content.
src/                        PHP, PSR-4 namespace SelectiveEntitySync\
  Plugin.php                Composition root: builds services, calls register_hooks().
  Contracts/                Interfaces (Hookable, collectors, handlers, rewriters…).
  Support/                  Cross-cutting helpers (Capabilities, I18n).
  Admin/                    Admin screen that hosts the React app.
  Identity/ Manifest/ Export/ Import/ Rest/ Cli/   Feature areas.
src-js/                     React admin app (built by @wordpress/scripts to build/).
tests/unit/                 PHPUnit + Brain Monkey. No WordPress loaded.
tests/integration/          PHPUnit + WP test suite, runs inside wp-env.
tests/e2e/specs/            Playwright + @wordpress/e2e-test-utils-playwright.
languages/                  .pot / translations.
```

## Rules

### Architecture

- **OOP only.** Namespaced PSR-4 classes in `src/`. No global functions or variables, except in the bootstrap file and test bootstraps.
- One responsibility per class. Use **constructor injection**. No singletons or static state. Wire services in `Plugin`.
- Hooks are added **only** in `register_hooks()` of classes implementing `Contracts\Hookable`, never in constructors.
- Extension points use interfaces plus filtered registries (collectors, import handlers, reference rewriters). Add-ons extend by registering implementations, not by patching core.

### Compatibility

- **PHP ≥ 7.4.**
  - Don't use enums, `readonly`, `match`, constructor property promotion, named arguments, union or intersection types, `mixed`/`never`/`static` return types, nullsafe `?->`, `str_contains()` or the other PHP 8 string functions.
  - Typed properties and arrow functions are fine.
- **WordPress ≥ 6.9.** Use modern core APIs available in 6.9 freely, such as DataViews, the Abilities API and script modules. Anything newer than 6.9 needs a `function_exists()`/version guard and a fallback.

### Coding standards and quality

- All code must pass `composer lint` (WordPress VIP Go + WordPress-Extra + PHPCompatibilityWP), `composer analyse` (PHPStan level 6) and `npm run lint`.
- No new `phpcs:ignore`, `@phpstan-ignore` or baseline entries without a specific sniff/identifier **and** a justification comment.
- Naming:
  - Classes and file names use PascalCase (PSR-4), for example `src/Import/IdMap.php`.
  - Methods, properties and variables use WordPress `snake_case`.
- Formatting: tabs for indentation, Yoda conditions.

### Security

- Check capabilities through `Support\Capabilities` on every entry point (admin, REST, CLI where relevant).
- Every REST route has a real `permission_callback`. Never use `__return_true` on mutating routes.
- Sanitize and validate early, escape late. Treat manifest contents as **untrusted input**: validate the schema, and guard against zip-slip, oversized or too-deep JSON and unexpected file types.
- Use `$wpdb->prepare()` for any direct query (prefer core APIs over direct queries).

### Internationalization

- Every user-facing string is translatable with text domain **`selective-entity-sync`**.
- PHP: `__()`, `esc_html__()`, `esc_html_e()`, `_n()`, `sprintf()` with numbered placeholders, and a `/* translators: */` comment for every placeholder.
- JS: `@wordpress/i18n` (`__`, `_n`, `sprintf`). Script translations are wired with `wp_set_script_translations()`.
- Never concatenate translated fragments into sentences.

### Hooks (extensibility)

- Every meaningful operation exposes actions or filters so site owners and add-ons can change behavior.
- Naming: `selective_entity_sync_{area}_{what}`, for example `selective_entity_sync_excluded_meta_keys` or `selective_entity_sync_before_import`.
- Every `apply_filters()` / `do_action()` call has a docblock immediately above it with a description, `@since x.y.z` and `@param` for every argument.
- Validate filter return values defensively and fall back to the default on invalid types.
- **Every hook must be documented in the "Hooks reference" section of `README.md` in the same change that adds or modifies it.** That means name, type, parameters, `@since` and a short example.

### Testing

- Every feature ships with tests:
  - Pure logic gets unit tests (`tests/unit`, Brain Monkey).
  - Anything touching the database or WP APIs gets integration tests (`tests/integration`).
  - User-facing flows get e2e tests (`tests/e2e`).
- Bug fixes start with a failing test.
- Test file names end in `Test.php` (PHP) or `.spec.js` (e2e).

### Git and docs

- Use Conventional Commits (`feat:`, `fix:`, `docs:`, `test:`, `chore:`, `refactor:`, `ci:`).
- Update `CHANGELOG.md` (Unreleased section) for user-facing changes.
- Keep `README.md` accurate: features, limitations, usage and the hooks reference.
- **Never commit AI tool files or folders** (`.claude/`, `.cursor/`, etc. are gitignored). `AGENTS.md` and `CLAUDE.md` are the only exceptions.
- `.internal/` is private planning material: never commit it or quote it in public files.

## Definition of done

1. `composer lint && composer analyse && npm run lint` pass.
2. `composer test:unit` passes. `npm run test:php` and `npm run test:e2e` pass when WP behavior or UI changed.
3. New strings are translatable, and new hooks are documented in `README.md`.
4. `CHANGELOG.md` is updated if the change is user-facing.
