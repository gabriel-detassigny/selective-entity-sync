@AGENTS.md

## Claude Code specifics

- `AGENTS.md` (imported above) is the source of truth for project rules. Update it, not this file, when a rule applies to every agent.
- Before reporting work as done, run the "Definition of done" checks from `AGENTS.md` and report the actual results.
- WordPress runs in Docker via wp-env. Run WP-CLI and integration tests through `npm run wp-env run …` / `npm run test:php`, not a host-level `wp` or `phpunit` against a WordPress install.
- Personal or local Claude settings go in `.claude/` or `CLAUDE.local.md`. Both are gitignored.
