# Architecture

How this repository is meant to be built. Where the code differs today is listed under
"Known deviations". Change this file in the PR that changes the structure.

## What it does

A Kimai plugin for trips and travel costs (`MileageBundle`). Users record trips with
vehicle, places and receipts; the plugin detects trips from the GPS history of a
Dawarich server, keeps a logbook with closed months and a team view, and gives the
yearly statement for the German tax return. Pages, a REST API, CSV import and export
and console commands all work on the same trips.

## Building blocks

| Path | Its one task |
|---|---|
| `Enum/` | The fixed choices of the domain (purpose, vehicle type, tax profile, month status, source of a trip). |
| `Entity/` | What is stored: trip, vehicle, place, rental, receipt, month lock, trip history, suggestion, detection run, the user's Dawarich key. |
| `Repository/` | The queries, one class per entity. |
| `Migrations/` | One version file per schema change. |
| `Doctrine/` | The guards on every write, whatever the way in: a closed month stays closed, every change to a trip is recorded (`TripAuditListener`); the Dawarich key is stored apart from the user's preferences (`DawarichKeyListener`). |
| `Service/` | The work. Today one flat folder; in the target grouped by kind: |
| – trips | Use cases on trips and their rights: defaults of a new trip, commutes, receipts on disk, month locks, who sees and approves whose trips (`TripService`, `CommuteGenerator`, `AttachmentStorage`, `MonthLockService`, `TeamService`). |
| – tax | The calculation, pure: rates per year, yearly statement, meal allowances, rental cost shares, logbook checks. Beside it the plausibility check before the tax return, which reads the database. |
| – detection | From GPS history to suggestions and from suggestions to trips: tracks, places, timesheets, the daily automatic run. |
| – outside servers | The only code that talks to another server: `DawarichClient`, `GeocoderClient`; the key in `DawarichKeyStore`. |
| – import, export | Trips from and to CSV and plain arrays (`TripCsvImporter`, `TripCsvExporter`, `TripMapper`, `CsvSafe`). |
| – settings | `MileageConfiguration`: system settings and user preferences with their defaults. |
| `Controller/` | The pages: trips, logbook, overview, tax report, team, vehicles, places, rentals, suggestions, import, receipts. The two traits hold what pages share: the user whose trips are shown, answers for modals and undo. |
| `API/` | The REST API for other programs. |
| `Command/` | Console commands: install, import, suggest. |
| `Form/`, `Twig/` | Symfony forms; the plugin's template functions. |
| `EventSubscriber/` | Hooks into Kimai: menu, permissions, settings, user preferences, page actions (`Actions/`), the start of the automatic detection. |
| `Resources/views/`, `Resources/public/` | Templates per page and their shared parts; the map library. `_kit/` and `kpu.*.xlf` are the vendored Kimai plugin kit, never edited here. |
| `Resources/translations/` | All texts, German and English. |
| `MileageBundle.php`, `DependencyInjection/`, `Resources/config/` | Registration in Kimai. |
| `tests/` | Unit tests per class; `e2e/` runs the pages in a real Kimai with a stand-in Dawarich server. |
| `demo/`, `docs/`, `scripts/`, `tools/` | Not part of the plugin zip: demo data, landing page, release and check scripts. |

## Layers

```
 entry points   Controller   API   Command   EventSubscriber   Form   Twig
                     │  ask for a use case, show the answer
                     ▼
 work           Service ─────────► Kimai's services      Dawarich, geocoder
                     │                                    (outside-server classes only)
                     ▼
 storage        Repository
                     │
                     ▼              Doctrine/ guards every write; asks Service for the rule
 model          Entity   Enum   Kimai's entities   (used by every layer above)
```

- An entry point calls services, never a repository or the entity manager.
- An entry point holds no rule. Who may see, change or delete what, and what a closed
  month allows, is decided once in a service; pages, API, menu and import ask it.
- A use case exists once as a service function, whichever entry point starts it: a new
  commute, deleting a trip or a rental with its receipt files.
- The guards in `Doctrine/` are the last line, not the only one: they stop a wrong
  write that an entry point let through. They are called by the database layer and
  use services: `MonthLockService` for the rule, `DawarichKeyStore` to store the key.
- A query is written in `Repository/`, also one for Kimai's timesheets; a service
  asks a repository and builds none itself. A repository may implement an interface
  a service defines (`DawarichKeyStore`).
- The calculation (rates, yearly statement, allowances, cost shares, distances, trips
  from tracks) uses no database and no service of Symfony or Kimai; it works on the
  model, plain values and the settings from `MileageConfiguration`, and has unit
  tests.
- Only the outside-server classes send requests. The Dawarich key is stored through
  `DawarichKeyStore` only and reaches no page, log or API answer.
- A CSV file is written by one building block (separator, number format, protection
  against formulas), whatever its columns.
- A setting's default stands once, in `MileageConfiguration`; the settings form takes
  it from there.
- `Service/` holds no code for pages or API answers: page headers live with the
  controllers, the API's self-description with the API.
- The stand-in Dawarich server exists once; demo and tests give it their places.

## Where new things go

| Change | Touch |
|---|---|
| A field on a trip | `Entity/Trip.php` and a migration; `Form/TripForm.php` and its template; `Service/TripMapper.php` (API and CSV); the CSV export; texts; a test |
| A tax rule or a rate of a new year | The tax services (`TaxRateSchedule`, `TaxCalculator`, `MealAllowanceCalculator`); a rate the administrator can set: its default in `MileageConfiguration`; the report template, README; a unit test |
| A rule on who may do what, or on closed months | The trips services (`TeamService`, `MonthLockService`); a new permission with its roles in `DependencyInjection/MileageExtension.php`; a unit test |
| A use case on trips, rentals or receipts | A function in the trips services; pages and API call it |
| How trips are detected | The detection services; `tests/Service/` with tracks as fixtures |
| Something new from Dawarich or another server | The outside-server classes; the stand-in server under `tests/e2e/` |
| A page | `Controller/`, a template folder in `Resources/views/`, the menu, page actions in `EventSubscriber/Actions/`, texts, a step in `tests/e2e/e2e.js` |
| An API endpoint or field | `API/MileageApiController.php`, `Service/TripMapper.php` or the input class, a feature name in `ApiInfo`, README |
| A setting | `Service/MileageConfiguration.php` (name and default), `EventSubscriber/SystemConfigurationSubscriber.php` or `UserPreferenceSubscriber.php`, texts |
| A table or column | `Entity/`, `Repository/`, a new file in `Migrations/` |
| A text | `Resources/translations/messages.de.xlf` and `messages.en.xlf`, same key |
| A design element that is missing | Knust or the kit in Kante, then sync; never `_kit/` here |

## Known deviations

- Pages, API and two commands use repositories directly (16 files), three of them the
  entity manager (not in `AUDIT.md`).
- Three services build queries themselves: `CommuteGenerator`, `PlausibilityChecker`
  and `SuggestionService` (not in `AUDIT.md`).
- Who may see, edit or delete a user's trips is decided in a trait of `Controller/`
  that pages and API share; the API checks edit and delete itself a second time
  (`AUDIT.md`, finding 2). The target is a service both ask.
- "Closed month only with the special permission" is checked anew in nine places
  (`AUDIT.md`, finding 1).
- Who may edit, delete or see the team stands twice: pages and API, team service and
  menu (`AUDIT.md`, finding 2).
- The defaults of eleven settings stand twice (`AUDIT.md`, finding 3).
- A new commute is put together in three places (`AUDIT.md`, finding 4); deleting a
  trip with its receipts stands twice, for rentals a third time
  (`AUDIT.md`, finding 5).
- CSV files are written by hand in three places, two of them in pages
  (`AUDIT.md`, finding 6).
- The target user is written into addresses 27 times (`AUDIT.md`, finding 7).
- `Service/` is one flat folder of 42 files and also holds the page headers
  (`MileagePages`) and the API's self-description (`ApiInfo`)
  (`AUDIT.md`, finding 8).
- The stand-in Dawarich server stands twice, for demo and tests
  (`AUDIT.md`, finding 9).
