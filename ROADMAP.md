# Roadmap — Kimai Anfahrten

**Idee:** Anfahrten und Fahrstrecken direkt in Kimai erfassen, die gefahrenen Kilometer automatisch aus
[Dawarich](https://dawarich.app) (selbst gehostete GPS-Historie) übernehmen und am Jahresende eine Aufstellung für das
Finanzamt erhalten. Dabei wird unterschieden, ob die Fahrt mit dem **eigenen Fahrzeug**, einem **Mietwagen**, einem
**Firmenwagen** oder mit Bahn bzw. Fahrrad gemacht wurde.

Leitlinien:

- **Kimai bleibt die Quelle für Arbeit, Dawarich für Bewegung.** Das Plugin verbindet beides: Zeiteintrag, Kunde,
  Projekt und Fahrt.
- **Vorschlagen statt raten.** Automatisch erkannte Fahrten landen als Vorschlag. Erst die Bestätigung durch den
  Nutzer macht daraus eine Buchung.
- **Datensparsam.** Gespeichert werden Strecke, Zeitfenster und Adressen, keine GPS-Tracks.
- **Steuerlogik ist konfigurierbar und versioniert.** Sätze ändern sich (zuletzt 2026). Keine Steuerberatung, nur
  eine Berechnungshilfe.

---

## Grundsatzentscheidungen (geklärt)

| Frage | Entscheidung |
|---|---|
| **Arbeitnehmer oder selbstständig?** | Selbstständig, aber **beides soll gehen** — über ein „Steuerprofil" pro Nutzer (Arbeitnehmer: Werbungskosten/Anlage N; Selbstständig: Betriebsausgaben/EÜR). Selbstständig ist der primäre, zuerst umgesetzte Fall. |
| **Plugin-Name** | Bundle bleibt vorerst **`MileageBundle`** (Repo heißt `kimai-anfahrten`). Umbenennen ist jederzeit möglich, aber nicht dringend. |
| **Anfahrten an Kunden weiterberechnen?** | **Nein**, nicht in diesem Plugin. Die eigentliche Rechnungsstellung läuft über Invoice Ninja. Das Plugin liefert stattdessen nur eine **Übersicht innerhalb von Kimai** (z. B. Fahrten nach Projekt/Kunde gefiltert, mit km und Kosten) zur **manuellen Übernahme** — siehe 0.5. |
| **Eine Dawarich-Instanz oder eine pro Nutzer?** | Bereits umgesetzt: Standard-URL im System, eigene URL und API-Key pro Nutzer. |

---

## 0.1 — Grundgerüst ✅ (aktueller Stand, ungetestet)

- [x] Fahrten mit Art (Arbeitsweg / Dienstreise / Privat), Fahrzeug (eigener PKW, Mietwagen, Firmenwagen, Motorrad,
      Fahrrad, ÖPNV, Sonstiges), Kennzeichen, Start/Ziel, km, Hin- und Rückfahrt, Kosten, Projekt, Bemerkung
- [x] Liste pro Monat/Jahr, anlegen / bearbeiten / duplizieren / löschen, CSV-Export
- [x] Dawarich: Distanz für ein Zeitfenster aus GPS-Punkten (`/api/v1/points`), Filter für ungenaue Punkte und GPS-Sprünge
- [x] „Fahrt erfassen" direkt am Zeiteintrag, Arbeitswege aus Tagen mit Arbeitszeit erzeugen
- [x] Jahresbericht: Entfernungspauschale (0,38 €/km, Deckel 4.500 € ohne PKW, ÖPNV-Istkosten), Dienstreisen
      (PKW 0,30 €/km, Motorrad 0,20 €/km, Mietwagen/ÖPNV mit Istkosten, Firmenwagen nicht abziehbar)
- [x] Systemeinstellungen (Sätze), Nutzereinstellungen (Dawarich, Wohn-/Arbeitsadresse, Entfernung, Standardfahrzeug)
- [x] Rechte, Übersetzungen de/en

## 0.2 — Lauffähig und abgesichert

Ziel: Das Plugin läuft stabil in der eigenen Kimai-Instanz.

- [ ] Test gegen Kimai ≥ 2.64 (Docker): Installation, Migration, Menü, Rechte, Profil-Einstellungen, Projektauswahl
- [ ] Dawarich-API gegen eine echte Instanz prüfen (Feldnamen, Seitenaufteilung, Zeitzonen, Rate-Limits)
- [ ] Button „Verbindung testen" in den Nutzereinstellungen
- [ ] API-Key nicht im Klartext im Profilformular anzeigen
- [ ] Unit-Tests für `DistanceCalculator`, `TaxCalculator`, `CommuteGenerator`
- [ ] GitHub Actions: `php -l`, PHPStan, PHP-CS-Fixer, PHPUnit
- [ ] Screenshots und README auf Deutsch

## 0.3 — Dawarich richtig nutzen (Kernidee)

Ziel: Fahrten nicht mehr eintippen, sondern bestätigen.

- [ ] **Automatische Fahrterkennung pro Tag:** GPS-Punkte in Bewegung und Stopps aufteilen (Geschwindigkeit,
      Standzeit) oder Dawarich-*Visits*/*Tracks* nutzen, wo verfügbar
- [ ] **Vorschlagsliste:** „Diese Woche erkannt: 6 Fahrten". Pro Vorschlag Art und Fahrzeug wählen, dann übernehmen
      oder verwerfen
- [ ] **Orte zuordnen:** Dawarich-*Areas* bzw. eigene Orte (Zuhause, Büro, Kunde X) erkennen, Kundenadresse aus Kimai
      als Ziel vorschlagen
- [ ] **Abgleich mit Zeiteinträgen:** Die Fahrt vor oder nach einem Zeiteintrag beim Kunden wird automatisch mit
      Projekt und Kunde verknüpft
- [ ] Rückwärts-Geokodierung für Start und Ziel (über Dawarich bzw. Photon/Nominatim, abschaltbar)
- [ ] Kartenvorschau der Strecke beim Bearbeiten (Leaflet, nur zur Anzeige, nicht gespeichert)
- [ ] Monatsabgleich als Konsolenbefehl oder Cronjob: `kimai:bundle:mileage:suggest --month=2026-09`

## 0.4 — Fahrzeuge und Fahrtenbuch

Ziel: finanzamtstaugliches Fahrtenbuch, z. B. für einen Firmenwagen statt 1-%-Regel.

- [ ] Eigene Fahrzeugverwaltung: Typ (eigen / Miete / Firma), Kennzeichen, Halter, gültig von–bis, mehrere Fahrzeuge
      pro Nutzer
- [ ] **Mietwagen als Vorgang:** Mietzeitraum, Anbieter, Kosten, Tankbelege. Fahrten im Zeitraum werden automatisch
      diesem Mietwagen zugeordnet, die Kosten anteilig verteilt
- [ ] Kilometerstand Beginn/Ende, Lückenprüfung
- [ ] Pflichtangaben Fahrtenbuch: Datum, km-Stand, Reiseziel, Reisezweck, aufgesuchte Geschäftspartner
- [ ] **Unveränderbarkeit:** Monate abschließen (wie die Monatssperre im HolidayBundle), Änderungen danach nur mit
      Protokoll
- [ ] Belege als Dateianhänge (Mietvertrag, Tankquittung, Bahnticket)

## 0.5 — Steuer vollständig (Fokus Selbstständige)

- [ ] **Steuerprofil pro Nutzer**: Selbstständig (EÜR) zuerst, Arbeitnehmer (Anlage N) danach — steuert, welcher
      Bericht und welche Feldbezeichnungen angezeigt werden
- [ ] **Selbstständig / EÜR:** betriebliche Fahrten als Betriebsausgabe; bei einem privat mitgenutzten Fahrzeug
      Privatanteil (Fahrtenbuch- oder 1-%-Methode) und Abgrenzung zum Home-Office/Betriebssitz statt „Arbeitgeber"
- [ ] **Sätze nach Jahr versioniert**, z. B. 2021–2025: 0,30 €/0,38 € ab km 21; ab 2026: 0,38 € ab km 1. Berechnet
      wird immer mit dem Satz des Fahrtjahres
- [ ] Verpflegungsmehraufwand bei Dienstreisen (14 € ab 8 h, 28 € ab 24 h) inkl. Dreimonatsfrist, abgeleitet aus
      Abwesenheitszeiten
- [ ] Sonderfälle: Sammelpunkt / weiträumiges Tätigkeitsgebiet, Familienheimfahrten, mehrere Tätigkeitsstätten
- [ ] Plausibilitätsprüfung: Arbeitswege > Arbeitstage? Arbeitsweg an Urlaubs-/Krankheitstagen (Anbindung an HolidayBundle)?
- [ ] PDF-Bericht mit Zuordnung zu den Formularzeilen, zum Beilegen für Finanzamt bzw. Steuerberater
- [ ] **Kunden-/Projektübersicht zur manuellen Übernahme** (kein Rechnungsversand aus Kimai heraus): Fahrten nach
      Kunde/Projekt und Zeitraum gefiltert, mit km, Kosten und Anlass — exportierbar, zum Abtippen z. B. in Invoice Ninja

## 0.6 — Team und Schnittstellen

- [ ] Freigabe-Workflow für Fahrten (Teamleitung), Teambericht
- [ ] REST-API `/api/mileage/...` (z. B. für Kurzbefehle auf dem Handy)
- [ ] CSV-Import (alte Fahrtenbücher, Exporte aus Auto-Apps)

## 1.0 — Veröffentlichung

- [ ] Landingpage via GitHub Pages (wie beim HolidayBundle), Screenshots, Doku
- [ ] Eintrag im Kimai-Marketplace, Release-Archive
- [ ] Übersetzungen prüfen, weitere Länder nur über konfigurierbare Sätze (keine länderspezifische Logik im Kern)

---

## Priorisierung

1. **0.2** zuerst: Ohne echten Test in Kimai und mit Dawarich bauen alle weiteren Schritte auf Annahmen.
2. **0.3** ist der eigentliche Mehrwert gegenüber einem normalen Fahrtenbuch.
3. **0.4 / 0.5** nach Bedarf: Firmenwagen mit Fahrtenbuch → 0.4 vorziehen. Viele Dienstreisen → Verpflegung aus 0.5
   vorziehen.
4. Eine echte **Kundenrechnungsstellung** (Anfahrtspauschale auf der Rechnung) ist bewusst **nicht** Teil dieser
   Roadmap — das übernimmt Invoice Ninja. Nur die Übersicht zur manuellen Übernahme (0.5) gehört dazu.
