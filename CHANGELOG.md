# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added

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
