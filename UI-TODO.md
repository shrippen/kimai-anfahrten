# UI-TODO – Umstellung auf kimai-plugin-ui

Grundlage: [kimai-plugin-ui GUIDELINES.md / CHECKLIST.md](https://github.com/shrippen/kimai-plugin-ui) (Kit 0.2.0,
siehe `Resources/views/_kit/VERSION`), Review-Befunde zur Oberfläche. Basis-Branch mit den Sicherheits-Fixes
(XSS in Karten-Tooltips, Eingabegrenzen, SSRF, CSV-Formeln, CSRF beim Dawarich-Test) bleibt unverändert wirksam.

## Seiteninventar

| Seite | Route | Vorlage (vorher) |
|---|---|---|
| Fahrten (Monat/Jahr) | `mileage_trips` | `trip/index` |
| Fahrt anlegen/bearbeiten/duplizieren | `mileage_trip_create`, `_edit`, `_duplicate` | `trip/edit` |
| Arbeitswege erzeugen | `mileage_commutes` | `trip/commutes` |
| CSV-Import | `mileage_import` | `import/index` |
| Erkannte Fahrten (Dawarich) | `mileage_suggestions` | `suggestion/index` |
| Orte | `mileage_places`, `_place_create`, `_place_edit` | `place/index`, `place/edit` |
| Fahrzeuge | `mileage_vehicles`, `_vehicle_create`, `_vehicle_edit` | `vehicle/index`, `vehicle/edit`, `_simple_form` |
| Fahrtenbuch (Fahrzeug, Jahr) + Druck | `mileage_logbook` | `logbook/index`, `_print` |
| Mietwagen | `mileage_rentals`, `_rental_show`, `_rental_create`, `_rental_edit` | `rental/*`, `_simple_form` |
| Monatsabschluss | `mileage_months` | `logbook/months` |
| Änderungsprotokoll | `mileage_history` | `logbook/history` |
| Kundenübersicht | `mileage_overview` | `overview/index` |
| Teamgenehmigung | `mileage_team` | `team/index` |
| Fahrtkosten (Steuer) + Druck/PDF | `mileage_tax_report` | `report/tax`, `_print` |
| Belege (Teil von Fahrt/Mietwagen) | `mileage_attachment_*` | `_attachments` |
| Karte (Teil von Orte/Fahrt) | – | `_map`, `_map_script` |
| Profil › Einstellungen (Dawarich, Steuerprofil) | Kimai-Core | `UserPreferenceSubscriber` |
| Zeiterfassung „Fahrt erfassen“ | Kimai-Core | `TimesheetTripSubscriber` |
| Systemeinstellungen | Kimai-Core | `SystemConfigurationSubscriber` |

## Alle Seiten
- [x] Kit 0.2.0 mit `bin/sync.sh` übernommen, Einbindung über `@Mileage/_kit/…` (Namespace geprüft: `@Mileage`)
- [x] Eigene Tab-Navigation `_nav.html.twig` entfernt. Entscheidung: Unterseiten sind die Kinder des Kimai-Menüs „Fahrten“
      (gibt es schon), Querverweise (Fahrtenbuch, Protokoll, Orte, Fahrten des Monats) als Seitenaktionen bzw. im „…“-Menü
- [x] Titel „Seite · Zeitraum“ über `PageSetup` (Service `MileagePages`), `setActionName()` + `setHelp(<volle URL>)` auf jeder Seite
- [x] Kontextzeile über `kit.context_line` (Benutzer, Fahrzeug, Zeitraum), kein `<h2>` im Inhalt
- [x] Seitenaktionen nur über `PageActionsEvent`-Subscriber (keine Textknöpfe im Inhalt)
- [x] Inhalt in `{% block main %}` statt `page_content`
- [x] Zeitraum über `kit.period_nav` (nur unterstützte Einheiten) statt «/» und „Ganzes Jahr“; „Woche“ gibt es nicht,
      weil keine Seite wochenweise rechnet (Steuer/Fahrtenbuch jahres-, Genehmigung monatsweise)
- [x] Listen über Kimai-DataTable bzw. `macros/datatables.html.twig`, Zeilenaktionen im „…“-Menü
- [x] Kein `confirm()`: Löschen über Kimai-Modal (`addDelete()` → Bestätigungsseite/-modal mit CSRF), Endgültiges
      (Monat einreichen/abschließen) mit Kimai-Bestätigung, Umkehrbares sofort + Rückgängig (GUIDELINES 3.5)
- [x] Anlegen/Bearbeiten im Kimai-Modal (Fahrzeug, Ort, Mietwagen, Fahrten erkennen, Ablehnen); Fahrt-Editor bleibt
      eine Seite (lang, Karte, Belege, Dawarich-Messung) mit Kimai-`_form`-Karte
- [x] Ergebnisse mit Zahlen als `kpu_result`-Callout (Import, Erkennen, Übernehmen, Arbeitswege, Orte-Import, Messung)
- [x] Modal-Formulare mit Ergebnis: `redirectToRouteAfterCreate()` bzw. leere 200 + `data-form-event: kpu.reload`
- [x] Leerzustände über `kit.empty_state` mit nächstem Schritt (Modal-Link)
- [x] Status über `kit.status_badge` (Vokabular siehe unten), Hinweise als `warning` mit Grund
- [x] Formate nur über Kimai-Filter (`date_short`, `date_time`, `time`, `money('EUR')`, `amount`, `month_name`):
      Zahlen folgen der Benutzersprache statt immer deutsch; km mit Einheit über Übersetzung (`mileage.unit.km`)
- [x] Formulare als FormTypes mit Kimai-Feldtypen (`DatePickerType`, `DateRangeType`, `CustomerType`), keine nativen
      `type="date"`-Felder, keine handgeschriebenen Formularfelder
- [x] Icon-Knöpfe nur als Kimai-Aktionen (Icon + Tooltip, mobil als „…“), Menü-Icons als Kimai-Aliase wo vorhanden
- [x] Keine Inline-Styles/-Handler, keine festen Farben (Karte: Höhe per CSS-Klasse, Hinweis `bg-body`, Linie `--tblr-primary`)
- [x] 390 px ohne waagrechtes Scrollen, Dunkelmodus, /de und /en

## Übersetzungen
- [x] Alle Keys mit Plugin-Präfix `mileage.` (vorher `trip.`, `tax.`, `menu.mileage` …); Einstellungs-/Präferenznamen unverändert
- [x] Plural-Texte decken 0 ab, Test `TranslationKeysTest::testPluralsCoverZero`
- [x] Glossar: „Benutzer“ statt „Nutzer“/„Mitarbeiter“
- [x] Glossar Monatsgenehmigung (Produktentscheidung, wie Kit-Status und HolidayBundle): „Genehmigen“/„Ablehnen“,
      Zustände „Genehmigt“/„Abgelehnt“, Einreichen „zur Genehmigung“ (EN Approve/Reject, Approved/Rejected) statt
      „Freigeben/Freigegeben/Freigabe“ und „Zurückweisen/Zurückgewiesen“; Übersetzungs-Keys (`mileage.approval.*`) unverändert
- [x] de/en gleicher Key-Bestand, `lint:xliff` grün

## Status-Vokabular (Zuordnung)

| Plugin-Zustand | Kit-Status | Anzeige |
|---|---|---|
| Monat ohne Abschluss | `open` | Offen |
| Monat abgeschlossen (ohne Genehmigung) | `locked` | Gesperrt |
| Monat eingereicht (wartet auf Teamleitung) | `requested` | Beantragt |
| Monat genehmigt | `approved` | Genehmigt |
| Monat abgelehnt | `rejected` | Abgelehnt (Grund als Tooltip/Text) |
| Erkannte Fahrt offen | `open` | Offen |
| Erkannte Fahrt im gesperrten Monat | `locked` | Gesperrt |
| Fahrtenbuch-/Import-Hinweis (km-Lücke, Duplikat) | `warning` | Warnung + Grund |
| Plausibilitätsbefund | `warning` | Warnung + Text |

Übernommene/verworfene Vorschläge erscheinen nicht mehr in der Liste (kein eigener Status nötig).

## Seiten

### Fahrten
- [x] Titel „Fahrten · September 2026“/„Fahrten · 2026“, Kontextzeile Benutzer (+ Monatsstatus)
- [x] `period_nav` Monat | Jahr
- [x] KPI-Leiste: km (Aufschlüsselung Dienst/Arbeitsweg/Privat), Entfernungspauschale, Reisekosten, absetzbar gesamt (hervorgehoben)
- [x] DataTable, Spalten auf 390 px: Datum, Strecke, km, „…“; Monatssperre als Status-Badge, Dawarich-Quelle als Icon mit Tooltip
- [x] „…“: Bearbeiten, Duplizieren, Protokoll, Löschen (Kimai-Modal)
- [x] Seitenaktionen: Fahrt, Arbeitsweg, Arbeitswege erzeugen (Monat), Import, Export, Dawarich-Verbindung testen (sofort, Ergebnis als Hinweis)
- [x] Hinweis offene Vorschläge / Dawarich nicht eingerichtet als Callout mit Link
- [x] Leerzustand mit „Fahrt erfassen“

### Fahrt-Editor
- [x] Kimai-`_form`-Karte, Titel „Fahrten · Fahrt bearbeiten“, Kontextzeile Benutzer
- [x] Seitenaktionen: Zurück, Protokoll, Duplizieren, Löschen; Belege mit Kimai-Bestätigung
- [x] Zeitfenster-Kasten ohne `bg-light` (Dunkelmodus), Dawarich-Messung als Ergebnis-Callout mit Benutzer-Zahlenformat

### Arbeitswege erzeugen / CSV-Import
- [x] FormTypes (`CommuteForm`, Upload-/Import-Formular mit Symfony-Feldern), Kimai-`_form`-Karte
- [x] Import-Vorschau: Zähler als KPI-Leiste, Duplikate als `warning`, Ergebnis als Callout

### Erkannte Fahrten
- [x] DataTable mit Checkbox-Auswahl, Sammelaktion Übernehmen/Verwerfen sofort + Rückgängig (15 min, gleiche Sitzung)
- [x] „…“: Übernehmen, als andere Art übernehmen, Übernehmen und bearbeiten, Start-/Zielort speichern (Modal), Verwerfen
- [x] „Fahrten erkennen“ als Modal mit Kimai-Datumsfeldern statt `type="date"`, Ergebnis als Callout
- [x] Leerzustand mit „Fahrten erkennen“ bzw. „Dawarich einrichten“

### Orte / Fahrzeuge / Mietwagen
- [x] DataTable, Anlegen/Bearbeiten im Modal, Löschen über Kimai-Modal, Leerzustand mit Modal-Link
- [x] Orte: Karte mit CSS-Klasse, Dawarich-Import als Sofort-Aktion mit Ergebnis
- [x] Mietwagen: Jahr über `period_nav`, Detailseite mit KPI-Leiste (Mietkosten, Tanken, Gesamt, Dienstanteil)

### Fahrtenbuch
- [x] Titel „Fahrtenbuch · 2026“, Kontextzeile Fahrzeug/Kennzeichen/Halter/km-Stand, Jahr über `period_nav`
- [x] KPI-Leiste km je Art mit Anteil, Warnungen als `status_badge('warning', grund)`
- [x] Seitenaktionen: Zurück, CSV, Drucken; Druckansicht mit Kimai-Formaten, ohne Inline-Handler

### Monatsabschluss / Teamgenehmigung
- [x] Monatsliste mit Status-Badges, „…“: Abschließen/Einreichen (Kimai-Bestätigung), Entsperren (sofort + Rückgängig)
- [x] Team: `period_nav` Monat, KPI-Leiste, Tabelle mit Status, Sammelaktion Genehmigen (+ Rückgängig), Ablehnen im Modal mit Pflicht-Grund

### Änderungsprotokoll / Kundenübersicht / Steuer
- [x] Protokoll als Tabelle mit `date_time`, Feldnamen übersetzt, Ja/Nein über `label_boolean`
- [x] Kundenübersicht: Filter als Formular mit `DateRangeType` + `CustomerType`, Gruppen über `kit.group_header`, KPI-Leiste, CSV als Seitenaktion
- [x] Steuer: Jahr über `period_nav`, Steuerprofil als Seitenaktion (Menü), KPI-Leiste, Befunde als `warning`, Beträge `money('EUR')`

## Offen / Hinweise
- [ ] `tests/e2e/e2e.js` an die neue Oberfläche angepasst, aber lokal nicht gegen eine frische Kimai-Datenbank
      gelaufen (der Testlauf löscht die Datenbank; die gleichen Abläufe wurden gegen die Test-Instanz im Browser geprüft)
- [ ] Kartenkacheln kommen von außen (OpenStreetMap); in abgeschotteten Umgebungen erscheinen Konsolenfehler der
      Kacheln – bewusst nicht geändert (Kartenkacheln dürfen bleiben)
- [ ] Kit-Lücken (an kimai-plugin-ui gemeldet, nicht im Plugin nachgebaut): `kpu-kpi-detail` bricht nicht um
      (lange Labels laufen bei 390 px über – Plugin nutzt kurze Labels), Kimai-Dropdown-Aktionen nehmen das Icon
      aus dem Schlüssel statt aus `icon` (Plugin: Schlüssel `fas fa-ellipsis-h` für „Weitere“)
