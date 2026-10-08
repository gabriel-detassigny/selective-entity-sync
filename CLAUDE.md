@AGENTS.md

## Claude Code specifics

- `AGENTS.md` (imported above) is the source of truth for project rules. Update it, not this file, when a rule applies to every agent.
- Before reporting work as done, run the "Definition of done" checks from `AGENTS.md` and report the actual results.
- WordPress runs in Docker via wp-env. Run WP-CLI through `npm run wp-env -- run …` (source site), `npm run wp-env:target -- run …` (target site) or `npm run wp-env:test -- run …` (test site), keeping the `--` so npm passes options like `--user=admin` through, and integration tests through `npm run test:php`, not a host-level `wp` or `phpunit` against a WordPress install.
- Personal or local Claude settings go in `.claude/` or `CLAUDE.local.md`. Both are gitignored.
