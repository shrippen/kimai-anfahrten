# Fahrten & Fahrtkosten für Kimai (MileageBundle)

Open-Source-Plugin für [Kimai](https://www.kimai.org/): Fahrten erfassen, **automatisch aus der GPS-Historie von
[Dawarich](https://dawarich.app) erkennen** und am Jahresende eine Aufstellung für die Steuererklärung bekommen —
für **Selbstständige (EÜR)** und **Arbeitnehmer (Anlage N)**. Eigenes Auto, Mietwagen, Firmenwagen, Bahn oder Fahrrad
werden jeweils steuerlich passend behandelt.

**Voraussetzung: Kimai ≥ 2.64** (getestet mit 2.67). Berechnungshilfe ohne Gewähr, keine Steuerberatung.

| | |
|---|---|
| **Plugin-ID** | `MileageBundle` |
| **Lizenz** | [GPL-3.0-or-later](LICENSE) |
| **PHP** | ≥ 8.1 |
| **Roadmap** | [ROADMAP.md](ROADMAP.md) |

Das Plugin hat einen eigenen Bereich **Fahrten** in der Seitenleiste (direkt unter Zeiterfassung).

![Fahrten](docs/fahrten.png)

## Funktionen

**Erfassen**
- Fahrten mit Art (Arbeitsweg, Dienstreise, Privat), Fahrzeug, Kennzeichen, Start/Ziel, km, Hin- und Rückfahrt,
  Übernachtung, Kosten, Projekt, Anlass
- „Fahrt erfassen" direkt am Zeiteintrag, Arbeitswege aus Tagen mit Arbeitszeit erzeugen, Fahrten duplizieren
- CSV-Import (erkennt Trennzeichen, Zeichensatz, deutsche/englische Spalten — auch den eigenen Export), REST-API

**Dawarich**
- Strecke für ein Zeitfenster aus GPS-Punkten messen, Kartenvorschau der Strecke
- **Automatische Fahrterkennung**: Stopps und Bewegungen werden getrennt, jede Fahrt landet als Vorschlag.
  Fuß-, Lauf- und Radwege werden über den **Transportmodus von Dawarich** ausgeschlossen (abschaltbar);
  bei Bahn, Bus oder Motorrad wird das passende Verkehrsmittel vorgeschlagen.
  Zuhause ↔ Büro wird als Arbeitsweg vorgeschlagen, Fahrten rund um einen Zeiteintrag beim Kunden als Dienstreise
  mit Projekt. Übernehmen, bearbeiten oder verwerfen.
- Orte (Zuhause, Arbeit, Kunde) anlegen oder aus Dawarich-*Areas* übernehmen, optionale Adressauflösung
  (Nominatim/Photon), nächtlicher Abgleich per Cronjob

![Erkannte Fahrten](docs/erkannte-fahrten.png)

**Fahrzeuge & Fahrtenbuch**
- Fahrzeuge mit Kennzeichen, Halter, Nutzungszeitraum, km-Stand, Betriebsvermögen, 1-%-Regel/Fahrtenbuchmethode
- **Mietvorgänge**: Miet- und Tankkosten werden auf alle Mietwagen-Fahrten im Zeitraum nach km verteilt —
  abziehbar ist nur der Anteil der Dienstreisen
- Fahrtenbuch pro Fahrzeug und Jahr mit Prüfung auf km-Stand-Lücken und fehlende Angaben, CSV und Druck/PDF
- **Monatsabschluss** (danach nur mit Sonderrecht änderbar) und **Änderungsprotokoll** für jede Fahrt
- Belege (PDF, Fotos) zu Fahrten und Mietvorgängen

![Fahrtenbuch](docs/fahrtenbuch.png)

**Steuer**
- Steuerprofil pro Nutzer: **Selbstständig** (Betriebsausgaben/EÜR) oder **Arbeitnehmer** (Werbungskosten/Anlage N)
- Entfernungspauschale mit den **Sätzen des jeweiligen Jahres** (bis 2025: 0,30 € bzw. 0,38 € ab km 21; ab 2026:
  0,38 € ab km 1), einmal pro Tag, 4.500-€-Deckel ohne PKW, höhere ÖPNV-Kosten
- Dienstreisen: eigener PKW 0,30 €/km, Motorrad 0,20 €/km, sonst tatsächliche Kosten; Firmenwagen bzw.
  Betriebsvermögen ohne km-Pauschale
- **Verpflegungsmehraufwand** (14 €/28 €) inkl. mehrtägiger Reisen und Dreimonatsfrist — nur aus Abfahrt und
  Ankunft der Dienstreisen (ohne Zeiten keine Pauschale; Hin- und Rückfahrt eines Tages, die aneinander anschließen,
  zählen als eine Abwesenheit). Das Zeitfenster für die Dawarich-Messung („Von/Bis") ist davon getrennt.
- Selbstständige: **Privatnutzung** betrieblicher Fahrzeuge (1-%-Regel inkl. E-Auto/Hybrid-Faktor,
  0,03-%-Zuschlag Wohnung–Betrieb, Privatanteil nach Fahrtenbuch)
- **Plausibilitätsprüfung**: Arbeitswege ohne Arbeitszeit, am Wochenende, an Urlaubs-/Krankheitstagen
  (mit dem [HolidayBundle](https://github.com/shrippen/kimai-holiday-bundle)), fehlende Angaben, offene Monate …
- Jahresbericht im Browser und als **PDF**
- **Kundenübersicht**: Dienstreisen je Kunde mit km und Kosten, zum Übertragen in eine Rechnung (z. B. Invoice Ninja)

![Steuerbericht](docs/steuerbericht.png)

**Team**
- Optionale **Genehmigung durch die Teamleitung**: Monat einreichen → genehmigen oder mit Begründung ablehnen
- Teambericht pro Monat; Teamleitungen sehen nur ihre Teammitglieder

![Team-Genehmigung](docs/team-genehmigung.png)

## Installation

```bash
cd /pfad/zu/kimai/var/plugins
git clone https://github.com/shrippen/kimai-anfahrten.git MileageBundle
cd /pfad/zu/kimai
bin/console kimai:reload -n
bin/console kimai:bundle:mileage:install
```

`kimai:bundle:mileage:install` legt die Tabellen an und kopiert die Kartenbibliothek (Leaflet) nach
`public/bundles/mileage`. Bei Updates: `git pull` im Plugin-Ordner und dieselben zwei Befehle.

Docker (offizielles Image):

```bash
docker exec -it CONTAINER /opt/kimai/bin/console kimai:reload -n
docker exec -it CONTAINER /opt/kimai/bin/console kimai:bundle:mileage:install
```

Danach unter **System → Rollen** (Abschnitt *Fahrten*) die Rechte prüfen.

## Einrichtung

1. **Profil → Einstellungen**: Steuerprofil, Wohn- und Arbeitsadresse, Entfernung Wohnung–Arbeit,
   Standardfahrzeug, Kennzeichen, Dawarich-API-Key (in Dawarich unter *Account*) und — falls in den
   Systemeinstellungen erlaubt — eine eigene Dawarich-URL.
   Der API-Key wird nicht angezeigt (leer lassen = behalten, ein Leerzeichen = löschen) und liegt in einer eigenen
   Tabelle, nicht bei Kimais Benutzereinstellungen — `/api/users/me` und Rechnungsvorlagen enthalten ihn nicht.
2. **Fahrten → Fahrzeuge**: optional Fahrzeuge anlegen (für Fahrtenbuch, km-Stand, 1-%-Regel).
3. **Fahrten → Orte → Aus Dawarich-Areas übernehmen**, oder Orte selbst anlegen.
4. **Fahrten → Erkannte Fahrten → Fahrten erkennen** — oder automatisch per Cronjob:

   ```bash
   # jede Nacht die letzten zwei Tage aller Nutzer mit Dawarich-Zugang
   0 3 * * * /pfad/zu/kimai/bin/console kimai:bundle:mileage:suggest
   ```

**System → Einstellungen** (Abschnitte *Fahrten & Fahrtkosten* und *Fahrten automatisch erkennen*): abweichende
Sätze, Standard-Dawarich-URL, Geocoding-Server, Kartenkacheln (leer = keine Karten), Genehmigung durch Teamleitung,
Empfindlichkeit der Fahrterkennung.

Eigene Dawarich-URLs pro Benutzer sind standardmäßig **aus** (*Eigene Dawarich-URL pro Benutzer erlauben*): Die URL
ruft der Kimai-Server auf, ein Benutzer könnte damit sonst interne Dienste im Netz des Servers ansprechen.
Installationen, in denen schon eigene URLs eingetragen sind, bekommen die Einstellung beim Update eingeschaltet.

## Rechte

| Recht | Zweck | Standard |
|---|---|---|
| `mileage` | Fahrten, Berichte, Einstellungen | alle |
| `edit_own_mileage` / `delete_own_mileage` | eigene Fahrten | alle |
| `lock_mileage` | eigene Monate abschließen bzw. einreichen | alle |
| `view_team_mileage` | Fahrten der eigenen Teammitglieder sehen | Teamleitung |
| `approve_mileage` | Monate der Teammitglieder genehmigen oder ablehnen | Teamleitung |
| `view_other_mileage` / `edit_other_mileage` / `delete_other_mileage` | alle Nutzer | Admin |
| `approve_other_mileage` | alle Monate genehmigen oder ablehnen | Admin |
| `unlock_mileage` / `edit_locked_mileage` | Monate wieder öffnen / in abgeschlossenen Monaten ändern | Admin |

## REST-API

Authentifizierung mit einem Kimai-API-Token (`Authorization: Bearer …`, *Profil → API-Zugang*).

| Methode | Pfad | |
|---|---|---|
| GET | `/api/mileage/ping` | Plugin installiert, Version, API-Versionen, Rechte, Profil und abgeschlossene Monate des Token-Inhabers (siehe unten) |
| GET | `/api/mileage/meta` | Arten, Fahrzeugtypen, Steuerprofile |
| GET | `/api/mileage/trips?year=2026&month=9` | Fahrten eines Monats bzw. Jahres |
| GET | `/api/mileage/trips?from=2026-09-01&to=2026-09-30` | Fahrten eines Zeitraums (beide Tage einschließlich, höchstens 366 Tage; hat Vorrang vor `year`/`month`) |
| POST | `/api/mileage/trips` | Fahrt anlegen, z. B. `{"distanceKm": 12.5, "destination": "Kunde"}` oder `{"purpose": "commute"}`; `"timesheet": 123` verknüpft einen eigenen Zeiteintrag |
| GET / PATCH / DELETE | `/api/mileage/trips/{id}` | einzelne Fahrt |
| GET | `/api/mileage/vehicles` | Fahrzeuge |
| GET | `/api/mileage/suggestions` | offene erkannte Fahrten, optional `?from=…&to=…` wie bei den Fahrten |
| POST | `/api/mileage/suggestions/{id}/accept` | übernehmen, optional `purpose`, `vehicle`, `project`, `distanceKm`, `comment`, `timesheet` |
| POST | `/api/mileage/suggestions/{id}/dismiss` | verwerfen |
| GET | `/api/mileage/tax/2026` | Jahreszusammenfassung |

Andere Benutzer mit `?user=<id>` (nur mit den passenden Rechten, sonst 403). Ungültige Eingaben ergeben 400 mit
`{"errors": {"feld": "Meldung"}}`, abgeschlossene Monate ohne `edit_locked_mileage` 403.

**`{"purpose": "commute"}`** übernimmt Entfernung und Adressen aus *Profil → Einstellungen*. Ist dort keine
Entfernung eingetragen (oder 0), gibt es ohne `distanceKm` einen Fehler 400 statt einer Fahrt mit 0 km.

**`ping`** braucht nur API-Zugang (nicht das Recht `mileage`), damit ein Client „nicht installiert" (404) von
„nicht erlaubt" (`permissions.view: false`) unterscheiden kann. Dawarich-URL und API-Key sind nie enthalten.

```json
{
  "installed": true, "pluginVersion": "0.9.0", "apiVersions": ["v1"],
  "permissions": {"view": true, "editOwn": true, "deleteOwn": true, "editLocked": false,
                  "viewTeam": false, "viewOther": false, "editOther": false},
  "features": ["tripTimesheet", "dateRange", "acceptFields", "commuteCheck"],
  "profile": {"commuteKm": 12.5, "defaultVehicle": "own_car", "defaultVehicleId": 6, "dawarichConfigured": true},
  "lockedMonths": ["2025-12", "2026-01"]
}
```

`lockedMonths` sind die abgeschlossenen, eingereichten oder genehmigten Monate des laufenden und des vorigen Jahres.
`defaultVehicleId` ist das einzige aktive Fahrzeug, das heute gilt (sonst `null`).

**Vorschlag übernehmen** mit Änderungen — `timesheet` muss ein Zeiteintrag des Benutzers des Vorschlags sein und
setzt dessen Projekt, wenn `project` fehlt; ohne `distanceKm` gilt die erkannte Strecke bzw. bei Arbeitswegen die
Entfernung aus dem Profil. Wird ein Arbeitsweg mit dem schon vorhandenen Arbeitsweg des Tages zusammengeführt, bleibt
diese Fahrt unverändert. Die Vorschläge (`GET /suggestions`) enthalten dafür auch `timesheet` (id oder `null`).

```bash
curl -X POST https://kimai.example.com/api/mileage/suggestions/15/accept \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"timesheet": 34, "distanceKm": 31.5, "comment": "Kundentermin"}'
```

Beispiel für einen Handy-Kurzbefehl „Arbeitsweg heute":

```bash
curl -X POST https://kimai.example.com/api/mileage/trips \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"purpose": "commute"}'
```

## Konsolenbefehle

```bash
bin/console kimai:bundle:mileage:suggest [--month=2026-09] [--days=2] [--user=name]
bin/console kimai:bundle:mileage:import fahrten.csv --user=name [--dry-run] [--keep-duplicates]
```

## Entwicklung

```bash
composer install
git clone --depth 1 --branch 2.67.0 https://github.com/kimai/kimai.git .kimai   # für PHPStan
composer check          # CS-Fixer (Prüfmodus), PHPStan Level 6, PHPUnit
```

Browser-Tests gegen ein echtes Kimai mit simuliertem Dawarich: [tests/e2e/README.md](tests/e2e/README.md).

### Oberfläche

Die Seiten folgen dem gemeinsamen UI-Leitfaden der Kimai-Plugins,
[kimai-plugin-ui](https://github.com/shrippen/kimai-plugin-ui) (`GUIDELINES.md`, `CHECKLIST.md`); das Kit liegt in
`Resources/views/_kit/` und `Resources/translations/kpu.*.xlf` und wird nur mit `bin/sync.sh` aus dem Kit-Repo
aktualisiert, nie von Hand. Kurz:

- Kimai-Bausteine zuerst: Seitenkopf über `PageSetup` (Service `MileagePages`, Titel „Seite · Zeitraum“, Hilfe-Link),
  Seitenaktionen und „…“-Menüs über `PageActionsEvent` (`EventSubscriber/Actions/`), Listen als Kimai-DataTable,
  Formulare als FormTypes im Kimai-Modal (der Fahrt-Editor ist eine eigene Seite).
- Unterseiten sind die Einträge des Menüs „Fahrten“; es gibt keine eigene Tab-Navigation.
- Zeitraum über `kit.period_nav`, Kennzahlen über `kit.kpi_bar`, Status über `kit.status_badge`
  (Monat: Offen/Gesperrt/Beantragt/Genehmigt/Abgelehnt, Hinweise als Warnung), Leerzustände mit nächstem Schritt.
- Umkehrbares (Vorschläge übernehmen/verwerfen, Monat wieder öffnen, Genehmigen) läuft sofort mit „Rückgängig“
  (15 Minuten, gleicher Benutzer und gleiche Sitzung), Löschen und Monat abschließen/einreichen fragen mit Kimais Modal.
- Zahlen, Datum und Beträge nur über Kimai-Filter (`amount`, `date_short`, `money('EUR')` …), also im Format des
  Benutzers; alle Texte über Übersetzungen mit dem Präfix `mileage.`.
Alles läuft auch in GitHub Actions (PHP 8.1–8.4, PHPStan, E2E auf MariaDB).

## Lizenz

GPL-3.0-or-later — siehe [LICENSE](LICENSE). Enthält [Leaflet](https://leafletjs.com) (BSD-2-Clause,
`Resources/public/leaflet/LICENSE`).
