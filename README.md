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

> **Podman users:** with rootless Podman, `wp-content` inside the containers can end up owned by root, so WordPress can't create `wp-content/uploads`. Create it once after the first `wp-env start` of each site:
>
> ```bash
> for c in $(podman ps --format '{{.Names}}' | grep wp-env-selective-entity-sync | grep -E 'wordpress-1|cli-1'); do
>   podman exec -u root "$c" sh -c 'mkdir -p /var/www/html/wp-content/uploads && chown 1000:1000 /var/www/html/wp-content/uploads'
> done
> ```

Contributor and agent guidelines live in [`AGENTS.md`](AGENTS.md).

## License

[GPL-2.0-or-later](LICENSE)
