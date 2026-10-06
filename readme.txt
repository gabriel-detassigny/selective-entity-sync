=== Selective Entity Sync ===
Contributors: gdetassigny
Tags: staging, deployment, sync, migration, content
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move selected posts, pages, terms and media between WordPress sites without overwriting live data. IDs and links are remapped automatically.

== Description ==

Selective Entity Sync copies the content you choose from one WordPress site to another, for example from staging to production, without migrating the database.

Copying a whole database (or whole tables) overwrites everything created on the target since the last sync: orders, user accounts, form entries, comments. Selective Entity Sync only moves the items you pick, plus everything they depend on, and leaves the rest of the target site alone.

= How it works =

1. On the source site, go to **Tools → Selective Entity Sync → Export**, select content and download a package (a zip file).
2. On the target site, upload it under **Import**. A preview shows what will be **created** or **updated**, and you can untick anything you don't want to change.
3. Import. Every reference is remapped to the target site's IDs and URLs.

= Features =

* **Dependencies included automatically**: parent pages, categories and tags (with their parent terms), featured images, media used in blocks and galleries, and synced patterns.
* **Safe to repeat**: every item gets a stable ID that travels with it, so syncing again updates the same content instead of duplicating it. Content created separately with the same slug is recognized too.
* **References remapped**: parent pages, featured images, term hierarchy, image, gallery, cover, file and video block IDs, `wp-image-…` classes, `[gallery]` shortcodes, media URLs (including every image size) and links to the source domain.
* **Media handled carefully**: files are uploaded with WordPress's usual checks and image sizes are regenerated; existing media is only replaced when the file actually changed.
* **Meta merged, not wiped**: custom fields in the package are updated; fields that only exist on the target are kept.
* **Large packages** are imported in batches with a progress bar.
* **Drafts, scheduled and private content** can be synced and keep their status.
* **WP-CLI**: `wp selective-entity-sync export` and `wp selective-entity-sync import`, including dry runs.
* **REST API** for automation.
* **Developer-friendly**: more than 25 actions and filters to change what is exported, how content is matched, which meta keys hold IDs, and more. See the [hooks reference](https://github.com/gabriel-detassigny/selective-entity-sync#hooks-reference).
* Translation-ready, with a French translation included.

= What is never synced =

Users, comments, orders and other non-content data, by design. Site settings, themes, plugins, menus, templates and global styles are not synced either.

== Installation ==

1. Install and activate the plugin on **both** the source and the target site.
2. Go to **Tools → Selective Entity Sync**.
3. Export from the source site, then import the package on the target site.

Imports from the admin screen are limited by PHP's upload size (`upload_max_filesize` and `post_max_size`). For very large packages, use WP-CLI on the target site:

`wp selective-entity-sync import package.zip --user=admin`

== Frequently Asked Questions ==

= Will importing overwrite my orders, users or form entries? =

No. Only the items in the package are created or updated. Nothing else on the target site is changed.

= What exactly ends up in a package? =

The items you select, plus what they need to display correctly: parent pages, categories, tags and their parents, featured images, media used in the content, and synced patterns. The export preview lists every item before you download. Child pages are not included unless you select them.

= What happens if I import the same package twice, or sync again later? =

Items are updated in place. Each item carries a stable ID (stored as hidden metadata) that is used to recognize it on the target, so nothing is duplicated.

= What if the target already has a page with the same slug that was created separately? =

It is treated as the same page and updated, then linked for future syncs. This avoids "contact-2" duplicates on sites that were set up separately. Developers can turn this off with the `selective_entity_sync_match_existing_entity` filter.

= Can I keep some items on the target unchanged? =

Yes. In the import preview, untick the items you don't want to create or update.

= Are custom fields supported? =

Custom fields (post meta and term meta) are copied. Fields that store IDs of other content (beyond featured images) are only remapped if a developer declares them with the `selective_entity_sync_id_reference_meta_keys` filter. Advanced Custom Fields relationship, post object, image and gallery fields are not remapped automatically yet.

= Some media wasn't imported. Why? =

The target site only accepts file types allowed for uploads there. For example, SVG files are rejected by a default WordPress install. The import report lists anything that was skipped or failed.

= Are links to other pages updated? =

Links that point to the source site's domain are rewritten to the target site's domain. Links whose page has a different slug on the target are not adjusted.

= Who can use it? =

Administrators (the `manage_options` capability) by default. Developers can change this with the `selective_entity_sync_capability` filter; WordPress's normal per-item permissions then still apply.

= Is it safe to import a package someone sent me? =

Only import packages you trust: a package is content from another site. Packages are validated before anything is extracted (sizes, file list, file types, checksums), and only content types can be written, never site configuration. Users without the `unfiltered_html` capability get their imported content filtered as usual.

= I use nginx. Anything to configure? =

Uploaded packages wait in `wp-content/uploads/selective-entity-sync-packages/` until they are imported. Their names are random and the folder blocks web access with an `.htaccess` file, which nginx ignores. Add a `location ^~ /wp-content/uploads/selective-entity-sync-packages/ { deny all; }` rule to block it there too.

= Does it work on multisite? =

Multisite networks haven't been tested yet. Syncing directly between sites of the same network isn't supported: export from one site and import on the other.

== Screenshots ==

1. Select the content to export, with search and filters by type and status.
2. The export preview lists the selected items and every dependency added automatically, with the package size.
3. The import preview shows what will be created or updated on this site. Untick anything you want to keep unchanged.
4. The import report links to every imported item.

== Changelog ==

= 0.1.0 =
* Initial release: export, import with preview and batching, ID and URL remapping, WP-CLI commands, REST API, French translation.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
