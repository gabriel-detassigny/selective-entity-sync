# Selective Entity Sync

> Push **specific content** from one WordPress environment to another without overwriting the target's live data.

Selective Entity Sync is a WordPress plugin for moving content changes safely between two WordPress sites, such as staging → production, production → staging, or between two live sites. It doesn't migrate the whole database, or whole tables, which would wipe out the orders, user accounts and form entries created on the target site since your last sync. Instead it exports the exact **entities** you pick (posts, pages, custom post types, terms, media) into a portable manifest, and imports them on the target site, **remapping every database ID** along the way.

> **Status:** early development (`0.1.0-dev`). Not ready for production use yet.

---

## Table of contents

- [Why](#why)
- [How it works](#how-it-works)
- [What gets synced](#what-gets-synced)
- [Limitations](#limitations)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Hooks reference](#hooks-reference)
- [Roadmap to launch](#roadmap-to-launch)
- [Development](#development)
- [License](#license)

## Why

Many teams prepare content on one environment, often a staging site, and then need it on another. If they move it by copying the database, then on any site with live activity (WooCommerce, memberships, forms, comments) they have to choose between:

- **overwriting the target's data** created since the last sync, or
- **re-creating the content by hand** on the target.

Selective Entity Sync is built for that kind of workflow. Staging → production is the most common case, but it works between **any two WordPress sites**: pulling a fixed-up page back down to staging, sharing content between sister sites, or seeding a new environment.

Table-level migration tools narrow the problem but don't solve it. A post's featured image, parent page, terms and the image IDs inside its blocks all point to auto-increment IDs that differ between sites.

Selective Entity Sync moves only what you choose, and fixes up those references on arrival.

## How it works

1. **Select.** On the source site, pick the posts/pages/CPT entries to sync from **Tools → Selective Entity Sync → Export**.
2. **Resolve dependencies.** The plugin follows references from your selection: parent posts, terms and their ancestors, featured images, and media used inside block content.
3. **Export.** You download a `.zip` package containing a versioned `manifest.json` and the media files.
4. **Preview.** On the target site, upload the package under **Import**. A dry run shows what will be **created**, **updated** or **skipped**, before anything is written.
5. **Import.**
   - Entities are matched to existing content by a stable **UUID** stored on each entity. If there's no UUID match, they're matched by a natural key (post type + slug, taxonomy + slug, file hash).
   - New entities get new IDs.
   - Every reference (post parent, `_thumbnail_id`, term parents, block attributes such as `id`/`ids`/`mediaId`, `wp-image-N` classes, `[gallery ids]`) is **rewritten** to the target site's IDs.

Content that isn't in the manifest is never touched.

## What gets synced

| Entity | Included |
|---|---|
| Posts, pages, custom post types | Content, excerpt, status, dates, slug, post meta, parent/child relations, menu order |
| Taxonomies and terms | Name, slug, description, hierarchy, term meta, post ↔ term relations |
| Media attachments | The file itself, title/caption/alt text, attachment meta. Image sizes are regenerated on the target |
| Authors | Mapped to an existing user by login/email (users are never created). Falls back to the importing user |

## Limitations

- **No ACF support** yet. ACF fields that store plain values are synced as post meta, but relational ACF fields (post object, relationship, image, gallery) aren't remapped.
- **No direct site-to-site push.** Packages are transferred as files.
- **Users, comments, orders and other non-content data are intentionally never synced.**
- Very large packages may hit PHP upload or memory limits. Use WP-CLI for big imports.

## Requirements

- WordPress **6.9** or later
- PHP **7.4** or later
- The PHP `zip` extension (`ZipArchive`)

## Installation

1. Download the latest `selective-entity-sync.zip` from the [Releases](https://github.com/gabriel-detassigny/selective-entity-sync/releases) page.
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload the zip.
3. Activate the plugin on **both** the source and the target site.

## Usage

### Admin

**Tools → Selective Entity Sync** has two tabs:

- **Export**: search and filter content, select entries, review dependencies, download the package.
- **Import**: upload a package, review the preview, confirm the import, read the result report.

By default only users with the `manage_options` capability can access the screen. See [`selective_entity_sync_capability`](#selective_entity_sync_capability).

### WP-CLI

_Coming soon._

```bash
wp selective-entity-sync export --post_ids=12,34 --file=/tmp/sync.zip
wp selective-entity-sync import /tmp/sync.zip --dry-run
wp selective-entity-sync import /tmp/sync.zip
```

## Hooks reference

Selective Entity Sync is built to be extended. All hooks are prefixed `selective_entity_sync_`. Add your code to a small custom plugin or mu-plugin.

### Actions

#### `selective_entity_sync_loaded`

Fires once the plugin has registered all of its services. This is the recommended place for add-ons to register their integrations.

| Parameter | Type | Description |
|---|---|---|
| `$plugin` | `SelectiveEntitySync\Plugin` | The plugin instance. |

Since `0.1.0`.

```php
add_action( 'selective_entity_sync_loaded', function ( $plugin ) {
	// Register custom collectors, handlers or rewriters here.
} );
```

### Filters

#### `selective_entity_sync_capability`

Filters the capability required to export and import content (admin screen and REST API). Invalid values fall back to the default.

| Parameter | Type | Description |
|---|---|---|
| `$capability` | `string` | Capability name. Default `manage_options`. |

Since `0.1.0`.

```php
// Let editors sync content.
add_filter( 'selective_entity_sync_capability', function () {
	return 'edit_others_posts';
} );
```

#### `selective_entity_sync_manifest_data`

Filters the manifest data just before it's written to a package. Use it to add custom data to entities or to the manifest. The `files` list is managed by the plugin, so changes to it are ignored, and the result must still be a valid manifest.

| Parameter | Type | Description |
|---|---|---|
| `$data` | `array` | Manifest data: `schema_version`, `generator`, `source`, `entities`, `files`. |
| `$manifest` | `SelectiveEntitySync\Manifest\Manifest` | The manifest being written. |

Since `0.1.0`.

```php
add_filter( 'selective_entity_sync_manifest_data', function ( array $data ) {
	foreach ( $data['entities'] as &$entity ) {
		$entity['data']['exported_by'] = 'deploy-bot';
	}
	return $data;
} );
```

#### `selective_entity_sync_package_limits`

Filters the limits enforced when reading an uploaded package. Invalid or non-positive values fall back to the defaults.

| Parameter | Type | Description |
|---|---|---|
| `$limits` | `array` | `max_package_size` (bytes, default 512 MB), `max_manifest_size` (bytes, default 64 MB), `max_entries` (files in the zip, default 20000), `max_uncompressed_size` (bytes, default 2 GB). |

Since `0.1.0`.

```php
add_filter( 'selective_entity_sync_package_limits', function ( array $limits ) {
	$limits['max_package_size'] = 2 * GB_IN_BYTES;
	return $limits;
} );
```

#### `selective_entity_sync_temp_file_lifetime`

Filters how long temporary working directories (packages being built or extracted) are kept before the daily cleanup deletes them.

| Parameter | Type | Description |
|---|---|---|
| `$lifetime` | `int` | Lifetime in seconds. Default `DAY_IN_SECONDS`. |

Since `0.1.0`.

```php
add_filter( 'selective_entity_sync_temp_file_lifetime', function () {
	return 6 * HOUR_IN_SECONDS;
} );
```

## Roadmap to launch

What's left before the first public release. Items are ticked as they land on `main`.

### Export

- [ ] REST endpoint listing selectable content (search, filter by post type and status)
- [ ] Dependency resolution: parent posts, terms and their ancestors, featured images, media used in blocks and `[gallery]` shortcodes, ID-bearing post meta
- [ ] Post, term and attachment collectors, with author info exported as login and email (mapped on import)
- [ ] Default excluded meta keys (`_edit_lock`, `_edit_last`, `_wp_old_slug`, …), filterable
- [ ] Export REST endpoint returning the package as a download
- [ ] WP-CLI: `wp selective-entity-sync export`

### Import

- [ ] Package upload, validation and storage between requests
- [ ] Preview (dry run): what will be created, updated or skipped, and why
- [ ] Matching existing content by UUID, falling back to natural keys (post type + slug, taxonomy + slug, file hash)
- [ ] Ordered import (terms → media → posts) building a UUID → local ID map
- [ ] Reference rewriting: block attributes (`id`, `ids`, `mediaId`, …), `wp-image-N` classes, `[gallery ids]`, `_thumbnail_id`, post and term parents, ID-bearing meta
- [ ] Media import: file sideloading, regenerated image sizes, deduplication
- [ ] Conflict strategies (update / skip / duplicate), filterable per entity
- [ ] Batched processing for large packages
- [ ] Import REST endpoints (preview, run)
- [ ] WP-CLI: `wp selective-entity-sync import <file> [--dry-run]`

### Admin UI

- [ ] Export tab: DataViews table with search, filters and multi-select, dependency summary, download
- [ ] Import tab: upload, preview table, confirmation, progress, result report

### Extensibility

- [ ] Export and import lifecycle hooks (`before_export`, `after_export`, `before_import`, `after_import`, `entity_imported`, `import_failed`)
- [ ] Filterable registries for collectors, import handlers and reference rewriters
- [ ] Abilities API integration (WordPress 6.9+) for export, preview and import (optional)

### Quality and maintenance

- [ ] End-to-end round-trip test: export, alter the target, import, assert every reference is remapped and unrelated content is untouched
- [x] Automated dependency updates and vulnerability alerts (Dependabot for Composer, npm and GitHub Actions; `composer audit` and `npm audit` in CI)
- [ ] Security review of all entry points (REST, uploads, WP-CLI)
- [ ] Translation files: generate the `.pot` and the JS translation JSON, and check with a non-English locale

### Release

- [ ] Finalise docs: `readme.txt` (FAQ, limitations such as ACF and SVG), `CONTRIBUTING.md`, screenshots
- [ ] Tag `v0.1.0` and publish the zip to GitHub Releases
- [ ] Make the GitHub repository public
- [ ] Submit to the WordPress.org plugin directory (Plugin Check, review, SVN deployment workflow)

## Development

Requirements: PHP 7.4+, Composer, Node.js 22+ (24 recommended, see `.nvmrc`), and Docker (or Podman with the Docker-compatible socket) for [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/).

```bash
composer install
npm install
npm run build
npm run wp-env start        # dev site:  http://localhost:8898 (admin / password)
npm run wp-env:test start   # test site: http://localhost:8899, used by test:php and test:e2e
```

| Task | Command |
|---|---|
| PHP coding standards (WP VIP) | `composer lint` / `composer lint:fix` |
| PHP static analysis | `composer analyse` |
| JS/CSS lint | `npm run lint` |
| PHP unit tests | `composer test:unit` |
| PHP integration tests | `npm run test:php` |
| End-to-end tests | `npm run test:e2e` |
| Generate translation template | `npm run makepot` |

**Accessing the dev site from another machine.** If wp-env runs on a VM or remote box, WordPress's default `localhost` URLs won't work from your own browser. Point the dev site at a hostname in the gitignored `.wp-env.override.json`, then run `npm run wp-env start -- --update`:

```json
{
  "config": {
    "WP_HOME": "http://mysite.wpenv.net:8898",
    "WP_SITEURL": "http://mysite.wpenv.net:8898"
  }
}
```

On the machine running the browser, map the hostname to the VM's IP in `/etc/hosts` (e.g. `192.168.1.50 mysite.wpenv.net`). `*.wpenv.net` resolves to `127.0.0.1` everywhere else, so WordPress's requests to itself (WP-Cron, REST API) keep working inside the container. Keep the test site on `localhost`, because Playwright runs on the same machine as wp-env. SSH port forwarding (`ssh -L 8898:localhost:8898 <vm>`) is an alternative that needs no config change.

Contributor and agent guidelines live in [`AGENTS.md`](AGENTS.md).

## License

[GPL-2.0-or-later](LICENSE)
