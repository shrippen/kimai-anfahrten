# Changelog

All notable changes to this project are documented in this file. Each release section is also its
release note (GitHub and Gitea) and its entry under "Changes" on the landing page.

## [Unreleased]

## [0.11.0] — 2026-10-29

### English

Trips from Dawarich are now detected by themselves: no button to press and no cron job to set up. Once a day the plugin looks at the past days and puts the trips under Detected trips for you to confirm.

#### New
- Automatic trip detection: once a day, after the first Kimai page of a user with Dawarich access, the browser starts the detection of the past days in the background; the page does not wait for it. It covers the days up to yesterday, so a trip still under way is never cut in half.
- The detected trips are suggestions as before: nothing is booked until you accept it. Closed months are skipped, and a trip that was already suggested is not suggested again.
- When Dawarich cannot be reached, the error is shown on Detected trips and the detection tries again after an hour; no Kimai page fails because of it.
- On by default, switched off in System → Settings ("Detect trips from Dawarich automatically") or per user in the preferences ("Detect trips automatically").
- Optional cron job `kimai:bundle:mileage:suggest --auto`, so the suggestions are ready without a page load. It shares its state with the page load, so nothing is detected twice.
- API: `DELETE /api/mileage/places/{id}` deletes a place, so tools that create places through the API can remove them again.
#### Note
- The detection keeps its state in a new table. After updating, run `bin/console kimai:reload -n` and then `bin/console kimai:bundle:mileage:install` (in Docker as `www-data`: `docker exec -u www-data …`). Without the table the automatic detection does not run and the Detected trips page fails with an error.
- The nightly `kimai:bundle:mileage:suggest` cron job from the install guide is no longer needed. If you keep a cron job, use `--auto`.

All changes: https://github.com/shrippen/kimai-anfahrten/compare/v0.10.0...v0.11.0

### Deutsch

Fahrten aus Dawarich werden jetzt von selbst erkannt: kein Knopf, kein Cronjob. Einmal am Tag schaut das Plugin auf die vergangenen Tage und legt die Fahrten unter Erkannte Fahrten zur Bestätigung ab.

#### Neu
- Automatische Fahrterkennung: einmal am Tag, nach der ersten Kimai-Seite eines Nutzers mit Dawarich-Zugang, startet der Browser die Erkennung der vergangenen Tage im Hintergrund; die Seite wartet nicht darauf. Erkannt wird bis gestern, eine Fahrt, die noch läuft, wird also nie halbiert.
- Die erkannten Fahrten sind wie bisher Vorschläge: gebucht wird erst, wenn du sie übernimmst. Abgeschlossene Monate werden übersprungen, eine schon vorgeschlagene Fahrt wird nicht noch einmal vorgeschlagen.
- Ist Dawarich nicht erreichbar, steht der Fehler unter Erkannte Fahrten, und die Erkennung versucht es nach einer Stunde wieder; keine Kimai-Seite scheitert daran.
- Standardmäßig an, abschaltbar unter System → Einstellungen („Fahrten automatisch aus Dawarich erkennen“) oder je Nutzer in den Einstellungen („Fahrten automatisch erkennen“).
- Optionaler Cronjob `kimai:bundle:mileage:suggest --auto`, damit die Vorschläge auch ohne Seitenaufruf bereitliegen. Er teilt den Stand mit dem Seitenaufruf, es wird also nichts doppelt erkannt.
- API: `DELETE /api/mileage/places/{id}` löscht einen Ort, damit Werkzeuge, die Orte über die API anlegen, sie auch wieder entfernen können.
#### Hinweis
- Die Erkennung speichert ihren Stand in einer neuen Tabelle. Nach dem Update `bin/console kimai:reload -n` und danach `bin/console kimai:bundle:mileage:install` ausführen (in Docker als `www-data`: `docker exec -u www-data …`). Ohne die Tabelle läuft die automatische Erkennung nicht, und die Seite Erkannte Fahrten bricht mit einem Fehler ab.
- Der nächtliche Cronjob `kimai:bundle:mileage:suggest` aus der Installationsanleitung ist nicht mehr nötig. Wer einen Cronjob behält, nimmt `--auto`.

Alle Änderungen: https://github.com/shrippen/kimai-anfahrten/compare/v0.10.0...v0.11.0

## [0.10.0] — 2026-10-06

### English

The first published release: log trips, have them detected from your Dawarich GPS history, keep a logbook per vehicle and get the yearly summary for the German tax return, for the self-employed (EÜR) and for employees (Anlage N).

#### New
- Trips with type (commute, business, private), vehicle, plate, start and destination, km, return trip, overnight stay, costs, project and reason; log one right from a timesheet entry or create commutes from days with working time.
- Trip detection from Dawarich: the driven parts of Dawarich's tracks become suggestions you accept, edit or dismiss. Walks, runs and bike rides are left out by Dawarich's transport mode; for train, bus or motorcycle the matching vehicle is suggested. Home to office is suggested as a commute, trips around a customer's timesheet entry as a business trip with project.
- Places with coordinates: your own, taken over from Dawarich areas and places, or created on the fly where a trip ends outside all places, so the next trip starts at the same place. Addresses come from Dawarich or an optional Nominatim/Photon server.
- Measure the distance of a time window from Dawarich, with a map preview of the route.
- Vehicles with plate, owner, odometer and usage period; rentals whose rental and fuel costs are split over the rental trips by km; a logbook per vehicle and year that checks for odometer gaps, as CSV and print/PDF; receipts (PDF, photos) for trips and rentals.
- Yearly tax summary in the browser and as PDF: commuting allowance with the rates of each year, business trip costs, meal allowance (including multi-day journeys and the three-month rule) and, for the self-employed, private use of business vehicles; with a plausibility check (also against vacation and sick days from the Working Hours & Holidays plugin).
- Customer overview of business trips with km and costs, to put on an invoice.
- Month closing with a change log for every trip; optionally team leads approve a submitted month or reject it with a reason, and see a team report.
- CSV import (also of the own export), a REST API under `/api/mileage` and an own "Fahrten" section in the Kimai sidebar, in German and English.
#### Note
- Needs Kimai 2.64 or newer and PHP 8.1 or newer. Install with `bin/console kimai:reload -n` and `bin/console kimai:bundle:mileage:install`.
- The Dawarich connection needs a Dawarich version with tracks. It is tested against a simulated Dawarich.
- A calculation aid without guarantee, not tax advice.

All changes: https://github.com/shrippen/kimai-anfahrten/commits/v0.10.0

### Deutsch

Die erste veröffentlichte Version: Fahrten erfassen, aus der GPS-Historie von Dawarich erkennen lassen, ein Fahrtenbuch pro Fahrzeug führen und die Jahresaufstellung für die Steuererklärung bekommen, für Selbstständige (EÜR) und Arbeitnehmer (Anlage N).

#### Neu
- Fahrten mit Art (Arbeitsweg, Dienstreise, privat), Fahrzeug, Kennzeichen, Start und Ziel, km, Hin- und Rückfahrt, Übernachtung, Kosten, Projekt und Anlass; direkt am Zeiteintrag erfassen oder Arbeitswege aus Tagen mit Arbeitszeit erzeugen.
- Fahrterkennung aus Dawarich: die gefahrenen Abschnitte der Dawarich-Tracks werden zu Vorschlägen, die du übernimmst, bearbeitest oder verwirfst. Fuß-, Lauf- und Radwege fallen über den Transportmodus von Dawarich heraus; bei Bahn, Bus oder Motorrad wird das passende Verkehrsmittel vorgeschlagen. Zuhause ↔ Büro wird als Arbeitsweg vorgeschlagen, Fahrten rund um einen Zeiteintrag beim Kunden als Dienstreise mit Projekt.
- Orte mit Koordinaten: eigene, aus Dawarich-Areas und -Places übernommene oder automatisch dort angelegte, wo eine Fahrt außerhalb aller Orte endet, damit die nächste Fahrt am selben Ort beginnt. Adressen kommen von Dawarich oder einem optionalen Nominatim-/Photon-Server.
- Die Strecke eines Zeitfensters aus Dawarich messen, mit Kartenvorschau der Route.
- Fahrzeuge mit Kennzeichen, Halter, km-Stand und Nutzungszeitraum; Mietvorgänge, deren Miet- und Tankkosten nach km auf die Mietwagen-Fahrten verteilt werden; ein Fahrtenbuch pro Fahrzeug und Jahr mit Prüfung auf km-Stand-Lücken, als CSV und Druck/PDF; Belege (PDF, Fotos) zu Fahrten und Mietvorgängen.
- Jährliche Steueraufstellung im Browser und als PDF: Entfernungspauschale mit den Sätzen des jeweiligen Jahres, Dienstreisekosten, Verpflegungsmehraufwand (mit mehrtägigen Reisen und Dreimonatsfrist) und für Selbstständige die Privatnutzung betrieblicher Fahrzeuge; mit Plausibilitätsprüfung (auch gegen Urlaubs- und Krankheitstage aus dem Plugin Arbeitszeiten & Feiertage).
- Kundenübersicht der Dienstreisen mit km und Kosten, zum Übertragen in eine Rechnung.
- Monatsabschluss mit Änderungsprotokoll für jede Fahrt; optional genehmigen Teamleitungen einen eingereichten Monat oder lehnen ihn mit Begründung ab und sehen einen Teambericht.
- CSV-Import (auch des eigenen Exports), eine REST-API unter `/api/mileage` und ein eigener Bereich „Fahrten“ in der Kimai-Seitenleiste, auf Deutsch und Englisch.
#### Hinweis
- Braucht Kimai 2.64 oder neuer und PHP 8.1 oder neuer. Installation mit `bin/console kimai:reload -n` und `bin/console kimai:bundle:mileage:install`.
- Die Dawarich-Anbindung braucht eine Dawarich-Version mit Tracks. Sie ist gegen ein simuliertes Dawarich getestet.
- Eine Berechnungshilfe ohne Gewähr, keine Steuerberatung.

Alle Änderungen: https://github.com/shrippen/kimai-anfahrten/commits/v0.10.0
