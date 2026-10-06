# Contributing to Selective Entity Sync

Thanks for helping! This page covers the practical side. The full set of rules (architecture, compatibility, security, i18n, hooks) lives in [`AGENTS.md`](AGENTS.md), which applies to humans and AI agents alike.

## Reporting bugs and requesting features

Open an issue with:

- what you did, what you expected and what happened;
- WordPress and PHP versions, and whether the site is a multisite network;
- for import problems, the import report (or the `wp selective-entity-sync import --dry-run` output). Please don't attach packages containing private content.

**Security issues:** don't open a public issue. Report them privately to the maintainer (see the Security section of the [README](README.md#security)).

## Development setup

You need PHP 7.4+, Composer, Node.js (see `.nvmrc`) and Docker, or Podman with its Docker-compatible socket.

```bash
composer install
npm install
npm run build
npm run wp-env start        # dev site:  http://localhost:8898 (admin / password)
npm run wp-env:test start   # test site: http://localhost:8899, used by the test suites
```

`npm run start` rebuilds the admin app on every change.

## Before opening a pull request

Run the checks from the "Definition of done" in `AGENTS.md`:

```bash
composer lint && composer analyse && npm run lint   # coding standards (WordPress VIP) and static analysis
composer test:unit                                  # unit tests, no WordPress needed
npm run makejson && npm run test:php                # integration tests in wp-env
npm run test:e2e                                    # Playwright end-to-end tests in wp-env
```

CI runs the same checks on PHP 7.4 and 8.3 with WordPress 6.9 and the latest release.

Also:

- **Tests:** every change comes with tests. A bug fix starts with a test that fails without the fix.
- **Hooks:** new or changed actions and filters are documented in the README's [Hooks reference](README.md#hooks-reference), with a `@since` tag in the code.
- **Strings:** user-facing strings are translatable. After changing them, run `npm run makepot`, then `composer updatepo`, and update the French translation in `languages/` if you can (or say so in the PR).
- **Changelog:** user-facing changes get a line in `CHANGELOG.md` under "Unreleased".
- **Commits** follow [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, `test:`, `chore:`, `refactor:`, `ci:`).

## Compatibility

The plugin supports **PHP 7.4+** and **WordPress 6.9+**. PHPCompatibility and PHPStan (run against WordPress 6.9) catch most mistakes, but please avoid PHP 8-only syntax and WordPress functions newer than 6.9 without a fallback.

## License

By contributing, you agree that your contributions are licensed under the [GPL-2.0-or-later](LICENSE), like the rest of the plugin.
