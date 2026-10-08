# AGENTS.md

Guidance for AI coding agents (and humans) working on **Selective Entity Sync**.

## What this project is

A WordPress plugin that moves **selected content** between two WordPress sites, most commonly Staging → Production, but any source → target pair works. It never runs a full or table-level database migration, so live orders, users and form entries on the target are never overwritten.

- The source site **exports** an entity manifest: a `.zip` holding `manifest.json` plus the media files.
- The target site **imports** it. Entities are matched by a stable UUID, and every relational ID is **remapped**: post parents, featured images, term hierarchy, and image/gallery IDs inside block content.

### Scope (v0.x)

- In scope:
  - Posts, pages and custom post types (with post meta and parent relations).
  - Taxonomies and terms (hierarchy and term meta).
  - Media attachments (files bundled).
  - Admin UI, REST API and WP-CLI.
- Out of scope for now: ACF, direct site-to-site push, users, WooCommerce orders, and any premium/commercial features. Don't add these to core. Design hooks so an add-on could add them.

## Commands

| Task | Command |
|---|---|
| Install deps | `composer install && npm install` |
| Start / stop both dev sites | `npm run sites:start` / `npm run sites:stop` (source http://localhost:8898, target http://localhost:8897, `admin` / `password`) |
| One dev site | `npm run wp-env …` (source) / `npm run wp-env:target …` (target) |
| Demo content | `npm run demo:source` / `npm run demo:target` (`dev/demo-content.sh`, idempotent) |
| Start / stop test site | `npm run wp-env:test start` / `npm run wp-env:test stop` (http://localhost:8899) |
| Build JS / watch | `npm run build` / `npm run start` |
| PHP lint / autofix | `composer lint` / `composer lint:fix` |
| PHP static analysis | `composer analyse` |
| JS + CSS lint | `npm run lint` |
| PHP unit tests (no WP) | `composer test:unit` |
| PHP integration tests (test site) | `npm run test:php` |
| E2E tests (Playwright, test site) | `npm run test:e2e` |
| WP-CLI | `npm run wp-env run cli wp <command>` (source) / `npm run wp-env:target run cli wp <command>` (target) / `npm run wp-env:test run cli wp <command>` (test) |
| Translations | `npm run makepot` (template) → `composer updatepo` (merge into `.po`) → translate → `npm run makejson` (`.mo` + JS `.json`) |
| Release zip | `composer install --no-dev -o && npm run build && npm run plugin-zip` |

### Environments

There are three separate wp-env environments, each with its own containers, database and uploads:

- **Source dev site** (`.wp-env.json`, port 8898): manual work and exporting. Tests never touch it.
- **Target dev site** (`.wp-env.target.json`, port 8897): importing packages exported from the source, for manual end-to-end checks of the sync. It maps the dev helper `dev/mu-plugins/batch-size.php`, which forces small batches when `SELECTIVE_ENTITY_SYNC_DEV_BATCH_SIZE` is set in `.wp-env.target.override.json`.
- **Test site** (`.wp-env.test.json`, port 8899): used by `npm run test:php` and `npm run test:e2e`. It maps the same dev helper, which e2e tests drive with an `X-SES-Batch-Size` header. PHPUnit reinstalls its database on every run, so e2e specs must set up their own state, for example activating the plugin in `beforeAll`.

Local-only tweaks go in the gitignored `.wp-env.override.json` / `.wp-env.target.override.json` / `.wp-env.test.override.json`. Keep `"testsEnvironment": false` in all three configs, and don't use the deprecated `env.tests` / `testsPort` options.

## Layout

```
selective-entity-sync.php   Bootstrap only: header, constants, env checks, autoload, boot Plugin.
uninstall.php               Removes plugin settings/temp files. Never removes imported content.
src/                        PHP, PSR-4 namespace SelectiveEntitySync\
  Plugin.php                Composition root: builds services, calls register_hooks().
  Contracts/                Interfaces (Hookable, collectors, handlers, rewriters…).
  Support/                  Cross-cutting helpers (Capabilities, I18n).
  Admin/                    Admin screen that hosts the React app.
  Exception/                SyncException, the base for all plugin exceptions (messages are translated).
  Identity/                 Entity UUIDs (meta `_selective_entity_sync_uuid`) and lookup by UUID.
  Manifest/                 Manifest model, schema validation, JSON codec, safe package paths.
  Package/                  Zip writer and reader (all untrusted-input checks live in PackageReader).
  Storage/                  Temporary working directories under get_temp_dir(), with daily cleanup.
  Content/                  Finds post IDs referenced by content (block attributes, wp-image-N, [gallery]).
  Export/                   Exporter (dependency walk), ExportContext, ExportSettings (filters), Collectors/.
  Rest/                     REST controllers under selective-entity-sync/v1.
  Cli/                      `wp selective-entity-sync` command.
  Import/                   ImportPlanner (matching), Importer (start/run in batches, ordering, writes),
                            ImportJob + ImportJobStore (job state, locks), BatchLimits, Handlers/,
                            MetaImporter, AuthorResolver, PackageStore (uploads kept between requests).
src-js/                     React admin app (built by @wordpress/scripts to build/).
tests/unit/                 PHPUnit + Brain Monkey. No WordPress loaded.
tests/integration/          PHPUnit + WP test suite, runs inside wp-env.
tests/e2e/specs/            Playwright + @wordpress/e2e-test-utils-playwright.
dev/                        Dev-only helpers, never shipped: demo-content.sh, mu-plugins/ mapped into the target and test sites.
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

### Dependencies

- npm `dependencies` = code that is **bundled into `build/`**. Everything else goes in `devDependencies`, including `@wordpress/*` packages that `@wordpress/scripts` externalises to WordPress core (`wp-components`, `wp-element`, …). CI audits only `dependencies` (`npm audit --omit=dev`).
- Before adding a bundled dependency, check it works with WordPress 6.9's React and core packages. `@wordpress/dataviews` is pinned to `~10.1.7`, the version WordPress 6.9 itself uses, because it relies on core's `@wordpress/components` (including private APIs). Only raise it together with the minimum WordPress version; e2e runs on 6.9 and latest to catch breakage.
- Never use `__experimental*` / `__unstable*` component APIs (ESLint enforces this).
- Composer runtime dependencies must support PHP 7.4 (`config.platform.php` enforces this). Prefer none: anything in `vendor/` ships in the release zip.
- Dependabot opens grouped update PRs weekly (Composer, npm, GitHub Actions) and security PRs as advisories appear. Merge them only when CI is green, and treat `@wordpress/*` majors as needing a manual check.

### Security

- Check capabilities through `Support\Capabilities` on every entry point (admin, REST, CLI where relevant).
- Every REST route has a real `permission_callback`. Never use `__return_true` on mutating routes.
- Sanitize and validate early, escape late. Treat manifest contents as **untrusted input**: validate the schema, and guard against zip-slip, oversized or too-deep JSON and unexpected file types.
- Use `$wpdb->prepare()` for any direct query (prefer core APIs over direct queries).
- Per-object rights go through `Support\ObjectPermissions` (read on export; create/edit/publish/author/terms/media on import). The plugin capability alone is not enough once a site lowers it.
- Import must never write non-content post types or arbitrary statuses: validate against `ExportSettings`.
- Admin imports run in batches across requests: anything an import needs later (IDs, URL replacements, fixups, report) must live in `ImportContext`/`ImportJob` state, not in object properties or `/tmp` files from an earlier request.

### Internationalization

- Every user-facing string is translatable with text domain **`selective-entity-sync`**.
- PHP: `__()`, `esc_html__()`, `esc_html_e()`, `_n()`, `sprintf()` with numbered placeholders, and a `/* translators: */` comment for every placeholder.
- JS: `@wordpress/i18n` (`__`, `_n`, `sprintf`). Script translations are wired with `wp_set_script_translations()`.
- Never concatenate translated fragments into sentences.
- After changing user-facing strings, run `npm run makepot` and commit `languages/selective-entity-sync.pot` (CI fails if it is stale). Then run `composer updatepo` and update `languages/*.po` (French is maintained in-repo), and run `npm run makejson`.
- JS translations are looked up by the hash of `build/index.js`, which is why `makepot` scans `build/`, not `src-js/`. Strings used only in JS go to the `.json` files, not the `.mo`.

### Hooks (extensibility)

- Every meaningful operation exposes actions or filters so site owners and add-ons can change behavior.
- Naming: `selective_entity_sync_{area}_{what}`, for example `selective_entity_sync_excluded_meta_keys` or `selective_entity_sync_before_import`.
- Every `apply_filters()` / `do_action()` call has a docblock immediately above it with a description, `@since x.y.z` and `@param` for every argument.
- Validate filter return values defensively and fall back to the default on invalid types.
- **Every hook must be documented in the "Hooks reference" section of `README.md` in the same change that adds or modifies it.** That means name, type, parameters, `@since` and a short example.

### Testing

- `npm run makejson` must have been run for the i18n integration tests (compiled translations are gitignored).
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
- Don't describe the plugin as a "free" version, or mention a "Pro" version, in public files until a paid version exists.

## Definition of done

1. `composer lint && composer analyse && npm run lint` pass.
2. `composer test:unit` passes. `npm run test:php` and `npm run test:e2e` pass when WP behavior or UI changed.
3. New strings are translatable, and new hooks are documented in `README.md`.
4. `CHANGELOG.md` is updated if the change is user-facing.
