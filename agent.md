# Working rules for this repository

The rules for all shrippen repositories are in `RULES.md` (writing, code, commits, bug
fixes, repository, GUI). It is a synced copy from the rules repository; do not edit it
here. This file holds only what is particular to this repository. A Claude Code session on
the owner's machine has the rules through the global `CLAUDE.md` already and reads
`RULES.md` only for its GUI rule, before work on a user interface.

## Release

Release steps: `RELEASE.md`. Before a release, load the skill `release-changelog`. A release
is published, so the owner confirms it in words.

## Repository (this project)

- CI runs on the GitHub mirror (`.github/workflows/ci.yml`, since 16.10.2026) and reports back to Gitea as the commit status `github / test`.
- The merge waits for: CI is green on the PR's latest commit: `github / test` from the GitHub mirror (`.github/workflows/ci.yml`: PHP lint and unit tests on PHP 8.1–8.4, PHPStan, PHP-CS-Fixer and the end-to-end tests in Kimai) shows success there. No status there yet means wait, not merge; if it never comes, check the mirror or ask the owner. PR-Agent's status does not count here.
- Everything else: `RULES.md` (Repository) and the skill `pr-review`.
