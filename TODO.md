# TODO — Code-Review MileageBundle (Branch `claude/kimai-plugins-code-review-yakjp9`)

Stand: Review gegen Kimai 2.67 (Live-Testinstanz, MariaDB) und statische Analyse.

Legende: ✅ = live reproduziert · 📖 = aus dem Code abgeleitet · **kein Fehler** = geprüft und widerlegt.
Checkbox abgehakt = behoben (mit Unit-Test und/oder Live-Nachtest, siehe „Nachtest").

Geprüft ohne Befund (kein Fehler, ✅ live mit admin/user1/user2/lead1):

- IDOR Web + API: fremde Fahrten, Fahrzeuge, Orte, Mietvorgänge, Belege, Vorschläge, Fahrtenbuch, Verlauf,
  Monatsabschluss, Genehmigung — alle 403 (`TargetUserTrait`, `canView/Edit/DeleteTripsOf`, `TeamService`).
- Team-Scoping: lead1 sieht nur Team A (user1), nicht user2; Genehmigung nur eigener Teammitglieder, nie sich selbst.
- CSRF: alle zustandsändernden Routen sind POST mit Token (Formulare über Symfony-Form-CSRF).
- Monatsabschluss: zentral im `TripAuditListener` erzwungen (auch für API, Import, Vorschläge) — kein Bypass,
  nur schlechte Fehlermeldung (siehe P2).
- `doctrine:schema:update --dump-sql`: keine Abweichungen für `kimai2_ext_mileage_*`; Installation mit
  `kimai:bundle:mileage:install` läuft durch.
- Steuersätze: Entfernungspauschale 2020–2026 (0,30/0,35/0,38 € ab km 21, ab 2026 0,38 € ab km 1),
  nur volle km, einmal pro Tag, 4.500-€-Deckel nur ohne eigenen/überlassenen PKW, Dienstreise 0,30/0,20 €/km,
  1-%-Regel mit Abrundung auf volle 100 €, 0,03-%-Zuschlag — korrekt.
- Profil-Einstellungen: zu lange Werte (> 255) werden von Kimai selbst abgefangen (kein 500).
- `/api/mileage/trips?year=99999`, `/api/mileage/tax/0000`: kein 500.
- Belege: Typ wird aus dem Inhalt erkannt (nur PDF/Bilder), `nosniff`, Dateiname mit `%` / 300 Zeichen ok.

## P0

- [x] ✅ **Stored XSS über Ortsnamen in der Karte** — `Resources/views/_map_script.html.twig:13`
  Leaflets `bindTooltip(string)` setzt den Text als HTML. user1 legt einen Ort
  `XSS<img src=x onerror=…>` an; beim Öffnen von *Orte* (auch `?user=2` durch lead1 und admin) läuft das Skript
  im Kontext des Betrachters (live: `window.__xss` gesetzt bei user1, lead1, admin) → Rechteausweitung zum Admin.
  Gleiches gilt für Dawarich-Area-Namen (Import). **Fix:** Tooltip als Textknoten (`textContent`) übergeben.
  Nachtest ✅: Playwright als user1/lead1/admin — Skript läuft nicht mehr, Tooltip zeigt den Text.

## P1

- [x] ✅ **500 statt Validierungsfehler bei zu langen Texten / zu großen Zahlen** —
  `Entity/Trip.php:50,53,62,77,104,109`, `Entity/Rental.php`, `Entity/Vehicle.php`, `Entity/Place.php`
  Web-Formular: Kennzeichen mit 40 Zeichen, Ziel mit 300 Zeichen, km-Stand 2147483648 → 500
  (`Data too long`, `Out of range`). API: `{"distanceKm": 1e999}` → 500 (`Incorrect double value: 'INF'`),
  `odometerStart: 3000000000` → 500. **Fix:** `Assert\Length` passend zu den Spalten, Obergrenzen (`Range`) für
  km, Kosten, km-Stand, Listenpreis; Mapper lehnt nicht-endliche Zahlen ab.
  Nachtest ✅: alle Fälle liefern Formularfehler bzw. 400 mit Feldfehler; `tests/Service/TripMapperTest.php`.
- [x] ✅ **API: Typ-Jonglage in `TripMapper::apply`** — `Service/TripMapper.php:84,155,167,206`
  `{"comment": ["x"]}` speichert den Text `"Array"` (live: Fahrt 3 von user1), Objekte/Arrays bei
  `purpose`/`vehicle`/`roundTrip`/`start`/… werden per `(string)` umgewandelt. **Fix:** nur Skalare akzeptieren,
  sonst 400 mit Feldfehler. Nachtest ✅: `{"comment": ["x"]}` → 400 `expected a string`.
- [x] ✅ **SSRF über die persönliche Dawarich-URL** — `Service/DawarichClient.php:196`,
  `Service/MileageConfiguration.php:134`
  Jeder Nutzer kann in seinen Einstellungen eine beliebige URL eintragen; der Server ruft sie mit
  „Verbindung testen", „Fahrten erkennen", Karte usw. auf und meldet Fehlertext bzw. HTTP-Status zurück.
  Live: `http://db:3306` → „Received HTTP/0.9 when not allowed for http://db:3306/…" → interne Dienste/Ports
  sind abtastbar, Redirects werden verfolgt. **Fix:** eigene URL pro Nutzer nur, wenn der Admin es in den
  Systemeinstellungen erlaubt (neue Einstellung `mileage.dawarich_user_url`; Migration schaltet sie für
  bestehende Installationen mit persönlichen URLs ein), nur `http`/`https`, keine Redirects.
  Nachtest ✅: ohne Einstellung wird die persönliche URL ignoriert (Feld im Profil ausgeblendet, „nicht
  eingerichtet"), `file://` wird auch mit Einstellung verworfen; Migration auf der Testinstanz ohne Wirkung
  (keine persönlichen URLs). Unit-Tests in `DawarichClientTest`.
- [x] ✅ **CSV-Formel-Injection in allen Exporten** — `Service/TripCsvExporter.php:44`,
  `Controller/LogbookController.php:173`, `Controller/OverviewController.php:99`
  Ziel `=HYPERLINK("http://evil","x")` / Bemerkung `+cmd|calc` landen unverändert im CSV; Admin/Teamleitung
  exportiert fremde Daten und öffnet sie in Excel. **Fix:** Textzellen, die mit `= + - @ Tab CR` beginnen,
  mit `'` entschärfen (`Service/CsvSafe.php`); der Import entfernt das `'` wieder.
  Nachtest ✅: Export und Kundenübersicht enthalten `'=HYPERLINK…` / `'+cmd|calc`.

## P2

- [x] ✅ **Teamleitung löst Dawarich-Anfragen mit den Zugangsdaten des Mitglieds aus** —
  `Controller/TripController.php:210` (`testDawarich`) prüft nur Sichtrecht. lead1 → `?user=2` erfolgreich,
  sieht dabei die Dawarich-URL des Mitglieds in der Fehlermeldung. **Fix:** Bearbeitungsrecht verlangen.
  Nachtest ✅: 403.
- [x] ✅ **Dawarich-API-Key im HTML** — `Form/Type/SecretType.php:21`
  Der Key steht als `value="…"` im Passwortfeld (Quelltext, Admin beim Bearbeiten fremder Einstellungen).
  **Fix:** Feld leer rendern, leer abgeschickt = bisherigen Wert behalten.
  Nachtest ✅: kein `value`, Profil speichern behält den Key; `tests/Form/SecretTypeTest.php`.
- [x] ✅ **Dawarich-API-Key über Kimais User-API sichtbar** — `GET /api/users/me` und (Admin)
  `GET /api/users/2` liefern `mileage_dawarich_api_key` im Klartext (Kimai serialisiert alle Präferenzen).
  **Entscheidung:** eigene Tabelle statt Präferenz. Eine interne Präferenz (`_…`) hätte nur die User-API
  abgedeckt — Kimai gibt *alle* Präferenzen auch an Rechnungsvorlagen (`user.meta.*`). Neu: Tabelle
  `kimai2_ext_mileage_user_secret` (`Entity/UserSecret`), Migration `Version20261001000000` verschiebt die Keys und
  löscht die alten Präferenzzeilen. Das Feld bleibt in *Profil → Einstellungen* (nur auf den Einstellungsseiten, nie
  beim Request-Boot) mit einem Platzhalterwert; `Doctrine/DawarichKeyListener` nimmt den Wert aus dem Flush und
  schreibt ihn in die Tabelle (leer = behalten, ein Leerzeichen = löschen — das Leerzeichen wurde vorher
  weggetrimmt und löschte nicht). Nachtest ✅: vorher `"value":"test-key"` in `/api/users/me`, nach der Migration
  weder in `/me` noch in `/api/users/2` (Admin) noch als Präferenzzeile; Formular leer/neu/Leerzeichen und
  `PATCH /api/users/2/preferences` wirken auf die Tabelle; Fahrterkennung gegen simuliertes Dawarich mit dem Key
  aus der Tabelle (6 Vorschläge); `doctrine:schema:update --dump-sql` ohne Abweichung.
- [x] ✅ **Beleg-Dateien bleiben liegen** — `API/MileageApiController.php:136`, `Controller/RentalController.php:115`
  `DELETE /api/mileage/trips/2` löscht die DB-Zeile (FK-Cascade), die Datei unter `var/data/mileage/2/` bleibt
  (live: 2 Dateien vorher und nachher). Gleiches beim Löschen eines Mietvorgangs. **Fix:** Dateien mitlöschen.
  Nachtest ✅: API-Löschen und Mietvorgang-Löschen entfernen die Datei (3 → 2).
- [x] ✅ **CSV-Import zerlegt Zeilenumbrüche in Anführungszeichen** — `Service/TripCsvImporter.php:54`
  `"Zeile1\nZeile2"` → Bemerkung abgeschnitten, Folgezeile „Distance missing" (live). Betrifft auch den eigenen
  Export mit mehrzeiligen Bemerkungen. **Fix:** Parsen mit `fgetcsv` über einen Stream.
  Nachtest ✅: Vorschau zeigt 2 gültige Zeilen; `ImportTest::testQuotedLineBreaks`.
- [x] ✅ **Arbeitswege/Import/Vorschläge in abgeschlossenen Monaten → 403-Fehlerseite** —
  `Service/CommuteGenerator.php:76`, `Service/TripCsvImporter.php` (build), `Controller/SuggestionController.php:86,121`
  Live: `POST /mileage/commutes/2026/9` mit `dates[]=2026-07-15` (Juli abgeschlossen) → 403-Seite statt Meldung.
  Der Listener verhindert das Speichern, aber der Nutzer bekommt eine Fehlerseite (Import bricht komplett ab).
  **Fix:** gesperrte Tage/Zeilen vorher überspringen bzw. als Fehler markieren und melden.
  Nachtest ✅: Arbeitswege → Hinweis „Monat abgeschlossen", Import → Zeilenfehler, Vorschlag (Web) → Meldung,
  „Alle übernehmen" → Hinweis, API → 403 mit Begründung.
- [x] 📖 **Arbeitswege: Datum außerhalb des Monats und Doppelte** — `Service/CommuteGenerator.php:76`
  Der POST nimmt beliebige Daten an (auch andere Jahre) und legt für Tage mit vorhandenem Arbeitsweg einen zweiten an.
  **Fix:** nur Tage des gewählten Monats ohne vorhandenen Arbeitsweg.
- [x] 📖 **Mietvorgang speichern verknüpft Fahrten in abgeschlossenen Monaten** — `Service/TripService.php:94`
  Der Mietvorgang ist schon gespeichert, dann wirft der Listener beim Verknüpfen → 403-Seite.
  **Fix:** gesperrte Fahrten nicht automatisch verknüpfen. Nachtest ✅: Mietvorgang für Juli (gesperrt) speicherbar.
- [x] 📖 **Fahrtenbuch: falsche Lücke am Jahresanfang** — `Service/LogbookService.php:37`
  Ab dem zweiten Jahr wird der erste km-Stand mit dem *Anfangs*-km-Stand des Fahrzeugs verglichen statt mit dem
  letzten km-Stand des Vorjahres → Warnung „Lücke" und riesige „nicht erfasste km". **Fix:** Startwert aus der
  letzten Fahrt vor dem 1.1. ableiten.
- [x] 📖 **Verpflegungsmehraufwand zählt Tage des Folgejahres** — `Service/MealAllowanceCalculator.php:108`
  Eine Reise vom 31.12. bis 2.1. landet komplett im Bericht des Abreisejahres (auch 1./2.1.).
  **Fix:** nur Tage des Steuerjahres zählen.
- [x] 📖 **„Strecke messen" ohne CSRF-Prüfung** — `Controller/TripController.php:297`
  Der Dawarich-Button hat `validation_groups: false`; der Zweig ruft `lookupDistance()` ohne `isValid()` auf, also
  auch ohne gültiges CSRF-Token (Cross-Site-POST löst eine Dawarich-Anfrage aus). **Fix:** Token prüfen.
- [x] 📖 **API-Lücke: `timesheet` nicht setzbar** — `Service/TripMapper.php` / `API/MileageApiController.php:226`
  Die Fahrt-JSON enthält `timesheet`, aber POST/PATCH ignorieren es. **Fix:** `timesheet` (ID) annehmen, nur eigene
  Zeiteinträge des Fahrt-Nutzers, sonst 400.
- [ ] 📖 **Verpflegungsmehraufwand hängt am Dawarich-Zeitfenster** — `Controller/TripController.php:401`,
  `Service/MealAllowanceCalculator.php`
  „Fahrt erfassen" am Zeiteintrag setzt 00:00–23:59 (für die Messung); die Pauschale rechnet dieselben Zeiten als
  Abwesenheit → jede so erfasste Dienstreise ergibt 14 €. Umgekehrt ergeben getrennt erfasste Hin- und Rückfahrt
  (Vorschläge) nur die Fahrzeit. **Offen:** Designentscheidung nötig (eigene Abwesenheitszeiten oder Fenster
  = Zeiteintrag ± Puffer); bis dahin ist die Zeile im Steuerbericht manuell zu prüfen.
- [ ] 📖 **Streckenmessung: Ausreißer als erster Punkt** — `Service/DistanceCalculator.php:42`
  Ist der erste Punkt ein GPS-Sprung, werden alle folgenden verworfen, bis die Zeit groß genug ist, dann wird der
  Sprung als Strecke gezählt. **Offen:** Algorithmus (z. B. Median-Filter/Neustart) — braucht echte Tracks zum Testen.
- [ ] 📖 **Belege an Fahrten in abgeschlossenen Monaten** — `Controller/AttachmentController.php:104`
  Hochladen/Löschen ist trotz Monatsabschluss möglich (nicht im Audit-Log). **Offen:** fachlich klären
  (Belege nachreichen ist oft gewollt).
- **kein Fehler** ✅ `GET /mileage/trip/{id}/track`: Teamleitung sieht die GPS-Spur der Fahrt eines Mitglieds —
  entspricht dem Sichtrecht auf die Fahrt (Zeitfenster der Fahrt); nur Hinweis für die Doku.

## UI-Beobachtungen (für die spätere UI-Kit-Integration, hier nicht geändert)

- Zahlen sind überall deutsch formatiert (`number_format(…, ',', '.')`), auch bei englischer Oberfläche.
- Datumsfelder gemischt: Formulare mit Kimai-Datepicker (`M/D/YYYY` in `en`), „Fahrten erkennen" und
  Kundenübersicht mit nativem `<input type="date">`.
- Löschen/Abschließen über native `confirm()`-Dialoge statt Kimai-Modals; Navigation `_nav.html.twig` als eigene
  Tab-Leiste; Karten mit Inline-Styles (`height: 320px`).
- Tabellen ohne einheitliche leere Zustände/Aktionen-Spalte, Aktionen teils als Icon-Buttons ohne Text.
- 403-Fehlerseiten statt Flash-Meldungen bei Sperrverletzungen (teilweise behoben, siehe P2).

## Werkzeuge

- `php -l` auf alle PHP-Dateien: ok.
- PHPUnit 10.5: 90 Tests / 284 Assertions grün (vorher 78). `composer install` scheitert im Sandbox-Netz an
  GitHub-Zip-Downloads; installiert wurde per `--prefer-source` ohne PHPStan/CS-Fixer in einem separaten Ordner.
- PHPStan 2.1 (Level 6, gegen Kimai 2.67.0-Quellen): keine Fehler. CS-Fixer nicht gelaufen (nicht installierbar).
