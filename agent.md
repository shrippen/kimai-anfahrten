# Working rules for this repository

## GUI rule

- This project is a Kimai plugin. Its GUI is generated from Knust (`kimai/knust/`) and the
  Kimai plugin UI kit (`kimai/kit/`, `kpu-*` markers and macros), both in
  https://github.com/shrippen/Kante (checkout `../Kante`). Knust is the Kante spinoff that
  adapts Kante to Kimai's look. Use their tokens, classes, markers, macros and components as
  they are, not inspired by them.
- The kit in `Resources/views/_kit/` is vendored unchanged; it changes only through
  `../Kante/kimai/kit/bin/sync.sh <plugin>` (`../Kante/tools/check-kit.sh` shows whether it is current).
- No own colours, fonts, sizes, radii, shadows, focus styles, animation timings, no own copy or
  variant of a component that Knust or the kit has. Raw values (`#hex`, `px` for controls) are a bug.
  Colours needed in JavaScript (charts) are read at runtime from `--knust-*` with a fallback.
- A missing element is added to Knust or the kit first (in the Kante repo), then used here.
  Never solve it locally. Where it would also help other projects, it is added to Kante as well.
- The landing page (`docs/`) is not Kimai: it uses Kante (`https://shrippen.github.io/v1/`).
- Rule text: https://github.com/shrippen/Kante/blob/main/AGENT-RULE.md

## Release rule

Release steps, in this order (`X.Y.Z` the new version, `A.B.C` the previous one). A release is done only after step 10.

1. On a branch from `main`, bump the version in `composer.json` (`version`; the release workflow checks the tag against it), `Service/ApiInfo.php` (`PLUGIN_VERSION`, sent by `ping`; `tests/Service/ApiInfoTest.php` compares both) and the `ping` example in `README.md` (`"pluginVersion"`).
2. Write the release log before the tag in `CHANGELOG.md`, the single source: a section `## [X.Y.Z] — YYYY-MM-DD` at the top (newest first), under `### English` and `### Deutsch` each a lead (what the release is about), the changes grouped as `#### New`, `Improved`, `Fixed` and `Note` (breaking changes, steps the user must take, e.g. `kimai:bundle:mileage:install` for a new table) and the compare link (`https://github.com/shrippen/kimai-anfahrten/compare/vA.B.C...vX.Y.Z`). Each point says what changed for the user and why, not which code moved. The internal demo is never mentioned. Check the headings with `scripts/release-notes.sh --check` (CI runs it on every push) and read the notes with `scripts/release-notes.sh X.Y.Z`.
3. Landing page `docs/index.html` (Kante `.changelog`): the same text, in English and German, as a new entry at the top of `#changes`; from the fourth entry on, the oldest move into `details.changelog-more`. Bump the footer version (`vX.Y.Z`, linked to `#changes`). Graphics wherever possible: a screenshot from the demo world per visible change (`docs/shots/release-<version>-<name>-<lang>.webp`; shots in `demo/shots.json`, taken with `../shrippen.github.io/demo/tools/.venv/bin/python ../shrippen.github.io/demo/tools/screenshots.py kimai-anfahrten`), a diagram (Kante `.flow` or inline SVG) for changes inside. Screenshots are taken now, for the tagged release, not with every change.
4. Open the PR on Gitea, review it as the Repository rule says (a release is published: the owner confirms it in words), merge on Gitea.
5. Tag the merge commit and push the tag to Gitea only: `git tag vX.Y.Z <merge commit> && git push origin vX.Y.Z` (the mirror carries it to GitHub).
6. Gitea release, notes only, never files: `scripts/release-notes.sh X.Y.Z > notes.md`, then `tea release create --repo shrippen/kimai-anfahrt --tag vX.Y.Z --title "Mileage X.Y.Z" --note-file notes.md`.
7. GitHub: the tag starts `.github/workflows/release.yml`. It checks the tag against `composer.json`, builds `MileageBundle-X.Y.Z.zip` (fails if `demo/` is in it), and publishes the release "Mileage X.Y.Z" with the same notes (fails without the section) and the zip. Check the run (`gh run list -R shrippen/kimai-anfahrten --workflow release.yml`) and the release (`gh release view vX.Y.Z -R shrippen/kimai-anfahrten`: notes and zip).
8. Update check: `python3 ../shrippen.github.io/overview/tools/build-versions.py`, then commit `docs/versions.json` in `shrippen.github.io` via a PR there.
9. Deploy to the live instance as `../CLAUDE.md` "Kimai-Plugins deployen" says, from a clean checkout of the tag (no local files):
   - `rsync -a -i --dry-run --filter=':- .gitignore' --exclude={.git,.gitea,.github,dev,tests,research,docs,reference,scripts,demo} --include=README.md --exclude='*.md' ./ regis.lan:/mnt/ApertureScience/docker/appdata/kimai/plugins/MileageBundle/`; check the list, then run it without `--dry-run`.
   - In the same SSH step: `chown -R www-data:www-data /mnt/ApertureScience/docker/appdata/kimai/plugins/MileageBundle`, directories `770`, files `664`.
   - `docker exec -u www-data kimai_app /opt/kimai/bin/console kimai:reload -n`, then `… console kimai:bundle:mileage:install` (migrations). Never without `-u www-data`.
   - Check: `docker logs --since 2m kimai_app | grep -E 'CRITICAL| 500 '` and `find /mnt/ApertureScience/docker/appdata/kimai/plugins/MileageBundle -not -user www-data` and `docker exec kimai_app find /opt/kimai/var -not -user www-data` are empty, the login page answers 200, the API without token 401 (not 500).
10. Done when https://shrippen.github.io/kimai-anfahrten/ shows X.Y.Z at the top of `#changes` and in the footer, and https://shrippen.github.io/versions.json lists `kimai-anfahrten` with X.Y.Z.

`scripts/release-notes.sh` is a copy of Kante's `tools/release-notes.sh`: change it only there, then sync with `../Kante/tools/sync-release-notes.sh "$PWD"`.

## Repository rule

- This repository lives on Gitea (`git.arianw.de`). GitHub is only a push mirror of it.
- CI runs on the GitHub mirror (`.github/workflows/ci.yml`, since 16.10.2026) and reports back to Gitea as the commit status `github / test`.
- Changes arrive as pull requests: work on a branch, open a PR, merge it on Gitea (the mirror follows).
- Never merge a PR, push to `main` (or any default branch), push tags or publish releases on GitHub. A merge there is overwritten by the next Gitea push.
- Never force-push a branch that someone else's PR depends on.
- Review (all shrippen repositories, owner 18.10. and 22.10.2026): every PR is reviewed before it is merged. The owner does not read code, so this is the second reader.
  - PR-Agent first (owner, 22.10.2026): where `.gitea/workflows/pr-agent.yml` is set up, the session comments `/review` on the PR for each round with new code or a new base (the first one included; a rebase, a merge of the base or a change of the base branch, see below) and waits for PR-Agent's comment. That comment reviews the whole diff and covers the commit that was the PR's head when `/review` was posted. PR-Agent labels its findings as `REVIEW.md` says; a finding without a label counts as **Wichtig**. A run cancelled by a newer `/review` on the same PR (the concurrency group holds the PR number) is replaced, not failed.
  - If PR-Agent fails (the run fails or ends without a review comment, reports a key or cost limit, or no run is created within 10 minutes), the session posts `/review` once more. If that fails too, a new Claude agent reviews the round as below, and the session says in the PR why. Where PR-Agent is not set up, the new Claude agent always reviews.
  - The Claude agent: the session that wrote the PR starts a new agent (Agent tool, no shared context) and gives it only the PR's title and body, the diff against its base (`git diff <base>...HEAD`), the repository and `REVIEW.md`. It does not tell it what it thinks of its own change. The agent checks every finding it would post against the code (file, line, the input that goes wrong) and drops what it cannot show. It reports as `REVIEW.md` says: count first, labels **Wichtig**, **Kleinigkeit**, **Bestand**. The session posts the report unchanged as a PR comment on Gitea. Above it goes the heading `## Claude-Review (neuer Agent) zu <commit>`, below it the footer `---` and `_Generated by [Claude Code](https://claude.ai/code)_`.
  - Each **Wichtig** is fixed, or answered in the PR with a reason.
  - PR-Agent sees no earlier round. So when an earlier round had a **Wichtig** (open or closed), after PR-Agent's comment (or with answers only, alone) a new Claude agent closes the round: it gets the PR's title and body, the repository, `REVIEW.md`, the open **Wichtig** with the answers, PR-Agent's findings of this round, the closed **Wichtig** and how they were closed, and the new commits (`git diff <last reviewed commit>..HEAD`; without new commits, the code at HEAD). It says for each open **Wichtig** whether it is fixed or its answer is accepted, and whether a **Wichtig** PR-Agent raises again is one already closed (fixed, answer accepted or decided by the owner; it stays closed unless the agent shows it is not fixed). Its count holds every **Wichtig** still open, PR-Agent's new ones included. It posts like a review, heading `## Claude-Review (offene Wichtig) zu <commit>`. An answer it does not accept goes to the owner, who decides in words. With the fallback, one new agent does both: the review of the new commits and the open **Wichtig**.
  - After a rebase, a merge of the base or a change of the PR's base branch a new round is due, also without a new commit, against the new base; a Claude agent of that round (closing or fallback) gets the whole diff against the new base (`git diff <base>...HEAD`) in place of the new commits. Every round counts, also one with answers only: after three rounds that still leave a **Wichtig** open, stop and rethink the approach with the owner.
  - Merge only when the last round covers the PR's latest commit against its current base branch (the branch by name; the base moving on alone needs no new round) and leaves no **Wichtig** open (its closing agent's count, or PR-Agent's comment when no earlier round had a **Wichtig**), and CI is green on the PR's latest commit: `github / test` from the GitHub mirror (`.github/workflows/ci.yml`: PHP lint and unit tests on PHP 8.1–8.4, PHPStan, PHP-CS-Fixer and the end-to-end tests in Kimai) shows success there. No status there yet means wait, not merge; if it never comes, check the mirror or ask the owner. PR-Agent's status does not count here.
  - Before the merge, the owner confirms in words, not by reading code, a PR that changes CI or release workflows (`.gitea/`, `.github/`), this review rule (`agent.md`, `REVIEW.md`), secrets or what is published (a release, a tag, a new public page, site or package; rebuilding what is already published does not count).
  - `REVIEW.md` in the repository root says what failure looks like here, what to check in particular, what to skip and how to report. A repository without one gets it in its next PR.
- PR-Agent's status is not a CI check. The owner may post `/review` at any time (PR-Agent takes no other command here); the merge waits for that run and handles its findings like a round's. Owner and session post from the same account, so a run that fails is handled the same whoever posted `/review`: as above, once more, then the fallback.
