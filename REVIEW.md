# Review guide

Every PR is reviewed before it is merged (rule in `agent.md`): by PR-Agent
(owner, 22.10.2026); a new Claude agent checks the **Wichtig** of earlier
rounds, and reviews when PR-Agent fails. The
reviewer knows nothing of the session that wrote the change: it gets the
PR's intent, its diff and the repository, and this file. The owner does not read code; this review is the second reader.

## What failure looks like here

Fahrten & Fahrtkosten (MileageBundle) is a Kimai plugin: it records trips,
detects them from the Dawarich GPS history and gives a yearly statement for
German tax returns (EÜR, Anlage N). A bug that matters is one a user files
with the tax office or sees in Kimai:

- **Wrong money:** rates of the wrong year, rounding, distances, the daily
  commuting allowance, meal allowances, rental cost shares, private use of
  company cars, or a vehicle type treated wrongly for tax purposes.
- **Wrong trips:** detection from Dawarich tracks that splits, merges or
  misses trips, places or overnight stays matched wrongly, year or month
  boundaries off by one.
- **Leaks:** controllers, API or exports without the role or ownership
  check they need, one user's trips or receipts reaching another, a team
  lead seeing more than their team, the Dawarich API key in logs, pages or
  `/api/users/me`, a user-set Dawarich URL reaching internal services.
- **Breaks Kimai:** a 500 on a page, migrations that do not match the
  entities, locked months changeable without the permission.
- **Looks wrong:** own colours, sizes or copies of kit macros instead of
  Knust and the kit; text missing in German or English.
- **Ships the demo:** code or data from `demo/` in the release zip.
- **Lies:** README, CHANGELOG or comments that say something the code does
  not do.

## What to check in particular

- Calculations and permissions have unit tests; GUI flows the end-to-end
  tests (`tests/e2e`).
- New translation keys exist in `messages.de.xlf` and `messages.en.xlf`.
- The kit in `Resources/views/_kit/` changes only by the kit's `sync.sh`.
- Entity changes come with a migration.
- Release: tag equal to the version in `composer.json`, an entry in
  `CHANGELOG.md`.
- Bug fixes: does the fix remove the cause, with a test that failed
  before?

## What the owner confirms here

"What is published" (rule in `agent.md`) means here: a release (a tag that
GitHub builds into the plugin zip), a change to the release workflow, a new
public page under `docs/`.

## Skip

- `Resources/views/_kit/` (vendored kit), `Resources/public/leaflet/`,
  screenshots and images.
- Formatting and style that PHP-CS-Fixer and PHPStan already check.

## How to report

- Every finding names file and line and the concrete input or state that
  goes wrong. No guess without evidence; if unsure, say so.
- Each finding gets one label: **Wichtig** (a bug from the list above,
  must be fixed or answered before the merge), **Kleinigkeit** (optional,
  at most five) or **Bestand** (the bug was there before this PR; not
  blocking).
- The report starts with the count, e.g. "2 Wichtig, 1 Kleinigkeit" (the
  session puts the heading above it). The count of **Wichtig** holds every
  one still open, also from an earlier round, e.g. "1 Wichtig (offen aus
  Runde 1)". Only when none is open it says plainly: "Nichts zu beheben."
- In a later round: say for each open **Wichtig** of the last round whether
  it is fixed, or whether its answer is accepted, with the reason. PR-Agent
  sees no earlier round: it reports only its own findings with their labels,
  and a new Claude agent closes the open ones (`agent.md`).
- Findings in German, code names as they are.
