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
- Tooling: wp-env, PHPCS (WordPress VIP), PHPStan, PHPUnit (unit + integration), Playwright e2e, GitHub Actions CI.
