# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Security

- Stored XSS: place and Dawarich area names were rendered as HTML in the map tooltips
- SSRF: personal Dawarich URLs now need the new system setting "Allow a personal Dawarich URL per user"
  (off by default; the update switches it on where personal URLs are already in use). Only http(s) URLs,
  redirects are not followed
- CSV exports (trips, logbook, customer overview) escape cells that spreadsheets would run as formulas
- The Dawarich API key is no longer written into the preferences page (leave the field empty to keep it)
- The Dawarich connection test for another user needs edit rights (team leads could use a member's key)
- "Measure with Dawarich" checks the CSRF token

### Fixed

- Too long texts and too large numbers (plate, locations, odometer, km, costs, …) caused HTTP 500 in forms,
  API and import instead of a validation error; the API rejects arrays/objects for text fields (was stored as "Array")
- Attachment files stayed on disk when a trip was deleted through the API or a rental was deleted
- CSV import broke rows with quoted line breaks (e.g. multi-line comments of the own export)
- Commutes, CSV rows and suggestions in closed months are skipped with a message instead of an error page;
  saving a rental no longer fails on trips of closed months; the commute generator only creates days of the
  chosen month that have no commute yet
- Logbook of a later year reported a gap from the vehicle's initial odometer
- Meal allowance of a journey over New Year counted the days of both years in one report

### Added

- REST API: `timesheet` (id of an own timesheet entry) can be set when creating or updating a trip
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
