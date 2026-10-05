# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Plugin scaffold: bootstrap with PHP/WP version checks, Tools → Selective Entity Sync admin screen (React), i18n loading.
- `selective_entity_sync_loaded` action and `selective_entity_sync_capability` filter.
- Stable entity UUIDs for posts, attachments and terms, used to match content across sites.
- Versioned manifest format (schema v1) with validation, and zip packages carrying manifest + media files.
- Hardened package reader: size/entry limits, only declared files accepted (no path traversal), declared sizes and SHA-256 checksums verified, disallowed file types rejected.
- Temporary working directories under the system temp dir, cleaned up daily.
- Hooks: `selective_entity_sync_manifest_data`, `selective_entity_sync_package_limits`, `selective_entity_sync_temp_file_lifetime`.
- Export: select posts, pages, custom post types and synced patterns; dependencies (parents, terms and their ancestors, featured images, media and patterns used in content, ID-bearing meta) are bundled automatically, or referenced only via a filter.
- REST API: `GET /entities`, `GET /export/options`, `POST /export/preview`, `POST /export` (zip download).
- WP-CLI: `wp selective-entity-sync export --post_ids=… [--file=…] [--dry-run]`.
- Export hooks: `before_export`/`after_export` actions, and filters for exportable post types, statuses and taxonomies, excluded and ID-bearing meta keys, block reference attributes, dependency inclusion, entity data and collectors.
- Admin: Export tab (Tools → Selective Entity Sync) with a searchable, filterable content table (DataViews), bulk selection, an export preview listing dependencies, files and warnings, and package download.
- Import: preview what will be created, updated, skipped, linked or missing; match existing content by UUID, then slug (or file checksum for media); write in dependency order; remap parents, terms, featured images, ID-bearing meta, block attributes, `wp-image-N`, `[gallery ids]`, media URLs and the site URL; replace media files only when their checksum changed; merge meta.
- Admin: Import tab with package upload, a preview where items can be deselected, and a result report with edit links.
- REST: `POST /import/packages`, `GET|DELETE /import/packages/{token}`, `POST /import/packages/{token}/import`. WP-CLI: `wp selective-entity-sync import <file> [--dry-run] [--skip=…]`.
- Import hooks: `before_import`, `entity_imported`, `import_failed`, `after_import` actions; `import_handlers`, `match_existing_entity`, `import_action`, `import_entity_data`, `import_author`, `replace_site_url` filters.
- Tooling: wp-env, PHPCS (WordPress VIP), PHPStan, PHPUnit (unit + integration), Playwright e2e, GitHub Actions CI.
