# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-08

First release.

### Added

- **Export** (Tools → Selective Entity Sync → Export): search, filter and select posts, pages, custom post types and synced patterns, including drafts, scheduled and private content. A preview lists every item and file, then you download a zip package.
- **Automatic dependencies**: parent pages, categories and tags with their parent terms, featured images, media used in blocks (image, gallery, cover, media & text, file, video, audio), `wp-image-N` classes, `[gallery]` shortcodes, synced patterns, and meta keys declared as holding IDs.
- **Import** (Tools → Selective Entity Sync → Import): upload a package and preview what will be created, updated, skipped, linked or missing. Untick items to leave them unchanged, import, and get a report with links to every item.
- **Matching**: items carry a stable UUID, so syncing again updates instead of duplicating. Content never synced before is matched by slug, or by file checksum for media, unless it already belongs to another synced item.
- **Remapping**: parents, terms, featured images, block IDs, `wp-image-N` and `data-id`, `[gallery ids]`, media URLs (every image size) and links to the source domain are rewritten for the target site.
- **Media**: uploaded with WordPress's usual checks and image sizes regenerated; existing media is only replaced when the file changed. Meta is merged: keys that only exist on the target are kept.
- **Batched imports** with a progress bar, so large packages stay within PHP limits. Batches work across multiple web servers and can't run twice at once.
- **WP-CLI**: `wp selective-entity-sync export` (`--post_ids`, `--file`, `--dry-run`) and `wp selective-entity-sync import` (`--dry-run`, `--skip`).
- **REST API** under `selective-entity-sync/v1` for listing content, previewing and downloading exports, and uploading, previewing, running and discarding imports.
- **Developer hooks**: 27 actions and filters (exportable types, statuses and taxonomies, excluded and ID-bearing meta keys, block reference attributes, dependency inclusion, collectors, import handlers, matching, import actions, authors, site URL replacement, batch and package limits, lifecycle actions), documented in the README.
- **Security**: administrators only by default (filterable), with per-item permission checks when the capability is lowered. Packages are validated before extraction (sizes, file list, safe paths, file types, checksums). Only content types can be imported. Uploaded packages are private to the uploader and expire after a day.
- **Translations**: translation template and a complete French translation.
- Requires WordPress 6.9+ and PHP 7.4+.

[Unreleased]: https://github.com/gabriel-detassigny/selective-entity-sync/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/gabriel-detassigny/selective-entity-sync/releases/tag/v0.1.0
