=== Selective Entity Sync ===
Contributors: gabrieldetassigny
Tags: staging, deployment, migration, sync, content
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Push selected content between WordPress sites, e.g. staging to production, without overwriting live orders, users or form entries. IDs are remapped automatically.

== Description ==

Selective Entity Sync exports the exact posts, pages, custom post type entries, terms and media you choose into a portable manifest, then imports them on another site: staging to production, production to staging, or between any two WordPress sites. Every relational ID is remapped on import: parent pages, featured images, term hierarchy, and image and gallery IDs inside blocks.

Unlike full database or table migrations, nothing outside your selection is touched. That makes it safe to use on live sites with WooCommerce orders, memberships or form submissions.

= Features =

* Select content to export from a searchable list.
* Automatic dependency resolution: parents, terms, featured images and media in block content.
* Dry-run preview before importing: see what will be created, updated or skipped.
* Stable UUID matching, so re-syncing updates content instead of duplicating it.
* WP-CLI commands for scripted deployments.
* Extensible through actions and filters.

= Not included =

* Users, comments and orders are never synced, by design.
* Relational ACF fields are not remapped.

== Installation ==

1. Upload the plugin zip from Plugins → Add New → Upload Plugin.
2. Activate it on both the source and target sites.
3. Go to Tools → Selective Entity Sync.

== Frequently Asked Questions ==

= Will importing overwrite my live orders or users? =

No. Only the entities contained in the package are created or updated.

= What happens if I import the same package twice? =

Entities are matched by a stable UUID, so a second import updates the same content instead of creating duplicates.

== Changelog ==

= 0.1.0 =
* Initial release.
