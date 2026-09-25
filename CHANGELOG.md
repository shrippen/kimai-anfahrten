# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Changed

- Dawarich: trips and distances come from the tracks Dawarich computes (`GET /api/v1/tracks`,
  `GET /api/v1/tracks/{id}`) and their transportation-mode segments instead of the plugin's own stop detection on
  raw GPS points. Consecutive driven segments form a trip; walking/running/cycling segments (if excluded) and
  standstills of at least the stop duration end it. Distance is the sum of Dawarich's segment distances; a segment
  cut by the measuring window counts in proportion to its time. "Test connection" checks the tracks API and counts
  tracks. Dawarich versions without tracks are no longer supported: the plugin reports "no tracks" instead.
- Places by coordinates: detected trips store their start/end coordinates and places. Where a trip starts or ends
  outside all places, a temporary place is created (radius setting `mileage.place_radius`, default 200 m), so the
  next trip from there starts at the same place; saving it in the form makes it a regular place. Dawarich places
  (`/api/v1/places`) are imported together with the areas. Addresses come from Dawarich's reverse geocoder
  (`/api/v1/places/nearby`), then from the plugin's geocoding server, and are kept on the place.
- Renaming a detected trip's start/destination by hand detaches it from the place.
- Removed the settings `mileage.dawarich_max_accuracy` and `mileage.detect_stop_radius` (and the GPS jump and
  leading outlier filters): Dawarich's own analysis is used

- User interface rebuilt with Kimai components and the shared UI kit
  ([kimai-plugin-ui](https://github.com/shrippen/kimai-plugin-ui) 0.2.0): page title with period and context line,
  page actions and row "…" menus instead of buttons in the content, period navigation (week/month/year where
  supported), KPI bars, Kimai data tables, status badges, empty states with a next step, result callouts
- The own tab navigation is gone; the plugin pages are the entries of the "Fahrten" menu
- Numbers, dates and amounts follow the user's locale (were always German)
- Vehicles, places, rentals, "detect trips" and rejecting a month open in Kimai's modal; deleting asks with
  Kimai's confirmation modal instead of the browser dialog
- Detected trips: selection with bulk "Accept"/"Dismiss", both with undo; the purpose is chosen in the row menu
  ("Accept as …") and the vehicle in the trip form ("Accept and edit") instead of inline selects
- Month closing: closing/handing in asks with Kimai's modal, reopening can be undone; team approval: approve
  (also for several members at once) with undo, reject with a required reason in a modal
- Departure/arrival use Kimai's date and time pickers
- Customer overview filter uses Kimai's date range and customer fields (old `from`/`to`/`customer` links still work)
- Month approval wording aligned with the status badges and the HolidayBundle: German "Genehmigen"/"Ablehnen",
  "Genehmigt"/"Abgelehnt", "zur Genehmigung einreichen" instead of "Freigeben"/"Zurückweisen" (English was
  already Approve/Reject); translation keys unchanged
- Translation keys carry the prefix `mileage.` (e.g. `trip.date` → `mileage.trip.date`,
  `menu.mileage` → `mileage.menu`); messages with a count cover 0
- Delete routes of trips, vehicles, places and rentals also answer GET (confirmation page for Kimai's modal);
  new routes for bulk/undo actions, the former single routes stay

### Security

- Stored XSS: place and Dawarich area names were rendered as HTML in the map tooltips
- SSRF: personal Dawarich URLs now need the new system setting "Allow a personal Dawarich URL per user"
  (off by default; the update switches it on where personal URLs are already in use). Only http(s) URLs,
  redirects are not followed
- CSV exports (trips, logbook, customer overview) escape cells that spreadsheets would run as formulas
- The Dawarich API key is no longer written into the preferences page (leave the field empty to keep it)
- The Dawarich connection test for another user needs edit rights (team leads could use a member's key)
- "Measure with Dawarich" checks the CSRF token
- The Dawarich API key is no longer a Kimai user preference: those are returned by `/api/users/me` and
  `/api/users/{id}` and handed to invoice templates. It lives in the new table `kimai2_ext_mileage_user_secret`
  (migration `Version20261001000000` moves existing keys and deletes the old preference rows). It is still edited in
  *Profil → Einstellungen* (or with Kimai's `PATCH /api/users/{id}/preferences`); a single space now really deletes
  the key (it was trimmed to empty and kept)

### Fixed

- A commute distance of 0 (or an invalid value) in the preferences counted as set: accepting a commute suggestion
  created a 0 km trip. It now counts as not set: suggestions keep the detected distance, "Arbeitsweg" in the trip
  form shows a hint to fill in the preferences, and `POST /api/mileage/trips {"purpose": "commute"}` without
  `distanceKm` answers 400 with that hint
- Too long texts and too large numbers (plate, locations, odometer, km, costs, …) caused HTTP 500 in forms,
  API and import instead of a validation error; the API rejects arrays/objects for text fields (was stored as "Array")
- Attachment files stayed on disk when a trip was deleted through the API or a rental was deleted
- CSV import broke rows with quoted line breaks (e.g. multi-line comments of the own export)
- Commutes, CSV rows and suggestions in closed months are skipped with a message instead of an error page;
  saving a rental no longer fails on trips of closed months; the commute generator only creates days of the
  chosen month that have no commute yet
- Logbook of a later year reported a gap from the vehicle's initial odometer
- Meal allowance of a journey over New Year counted the days of both years in one report
- Meal allowance used the Dawarich measuring window as absence: "Fahrt erfassen" at a timesheet stored
  00:00–23:59 as departure/arrival, so every such business trip got 14 €. The window is now a separate
  "From/To" pair in the trip form (not stored, defaults to departure/arrival or the whole day); departure/arrival
  are the real times and the only base of the meal allowance (trips without them get none, as before, and are
  listed as missing times). Existing trips with 00:00–23:59 are not changed automatically — please check them
- Distance measurement: an outlier as first GPS point (e.g. a stale fix before the GPS lock) made the following
  points look like jumps, so they were dropped and the jump was counted. Leading points that are more than 1 km
  and more than 200 km/h away from most of the next four points are now dropped first
- Meal allowance: legs of one day that start where the previous one ended (e.g. the detected way there and back)
  count as one absence from the first departure to the last arrival instead of only the driving time

### Added

- Receipts in closed months: adding one is allowed (handing in later), changing or deleting one needs
  "edit_locked_mileage" (enforced for every way of writing, with a message in the web UI). A rental counts as
  closed when a month of its period is closed. The trip page of a closed month is shown read-only with the
  receipts instead of redirecting to the list (row action "Belege"); receipts of trips appear in the change log
- REST API: `timesheet` (id of an own timesheet entry) can be set when creating or updating a trip
- REST API: `GET /api/mileage/ping` (plugin version, API versions, features, permissions, profile with commute
  distance/default vehicle/whether Dawarich is set up, locked months of this and the previous year; needs only API
  access and never contains the Dawarich URL or key)
- REST API: `from`/`to` (YYYY-MM-DD, at most 366 days) on `GET /trips` and `GET /suggestions`
- REST API: accepting a suggestion takes `project`, `distanceKm`, `comment` and `timesheet`; suggestions carry
  `timesheet`
- Own sidebar section "Fahrten" (after time tracking) with all plugin pages
- Dawarich transportation modes: walking, running and cycling parts of the tracks are excluded from
  trip detection and distance measurement (setting, on by default); bus/train/motorcycle suggest the vehicle.
  Works with Dawarich versions that have tracks with transportation modes, older ones are unaffected.

## [0.9.0] — 2026-09-24

Roadmap phases 0.2–0.6 (see ROADMAP.md).

### Added

- Automatic trip detection from the Dawarich history, suggestions inbox, places (incl. Dawarich areas),
  timesheet matching, optional reverse geocoding, map preview, `kimai:bundle:mileage:suggest`
- Vehicles, rentals with cost allocation, odometer and logbook per vehicle (CSV, print), receipts,
  month closing and change log
- Tax profiles (self-employed / employee), rates per year, meal allowance, private use of business vehicles,
  plausibility checks (optional HolidayBundle integration), PDF report, customer overview
- Approval workflow for team leads, team report, REST API, CSV import (web and console)
- Dawarich connection test, API key as password field
- Unit tests, PHPStan against Kimai, CS-Fixer, browser end-to-end tests against Kimai in CI

### Fixed

- Date query parameters were shifted to UTC by Kimai and dropped trips on the last day of a month
- Edit form and CSV showed UTC instead of local times
- Development folders were picked up by the service discovery

## [0.1.0] — 2026-09-24

### Added

- Trips (commute / business / private) with vehicle type, costs and project/timesheet link
- Dawarich integration: measure driven distance for a time window from GPS points
- Commute suggestions from timesheet days
- Yearly tax report and CSV export
- System settings for rates, user preferences for Dawarich and commute defaults
