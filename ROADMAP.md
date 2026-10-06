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

## Stand

Die Phasen 0.1–0.6 sind umgesetzt, bis auf die unten offen markierten Punkte. Geprüft wird automatisch:
Unit-Tests, PHPStan gegen den Kimai-Quellcode und Browser-Tests gegen ein echtes Kimai 2.67 mit simuliertem Dawarich
(GitHub Actions). **Noch nicht geprüft: eine echte Dawarich-Instanz** — siehe 0.2.

## 0.1 — Grundgerüst ✅

- [x] Fahrten mit Art (Arbeitsweg / Dienstreise / Privat), Fahrzeug (eigener PKW, Mietwagen, Firmenwagen, Motorrad,
      Fahrrad, ÖPNV, Sonstiges), Kennzeichen, Start/Ziel, km, Hin- und Rückfahrt, Kosten, Projekt, Bemerkung
- [x] Liste pro Monat/Jahr, anlegen / bearbeiten / duplizieren / löschen, CSV-Export
- [x] Dawarich: Distanz für ein Zeitfenster (anfangs aus GPS-Punkten, inzwischen aus den Dawarich-Tracks)
- [x] „Fahrt erfassen" direkt am Zeiteintrag, Arbeitswege aus Tagen mit Arbeitszeit erzeugen
- [x] Jahresbericht, Systemeinstellungen, Nutzereinstellungen, Rechte, Übersetzungen de/en

## 0.2 — Lauffähig und abgesichert ✅ (bis auf echte Dawarich-Instanz)

- [x] Test gegen Kimai 2.67: Installation, Migrationen (gegen das Entity-Mapping geprüft), Menü, Rechte,
      Profil-Einstellungen, Projektauswahl — ohne Docker mit MariaDB, als Browser-Tests auch in der CI
- [ ] **Dawarich-API gegen eine echte Instanz prüfen** (Feldnamen, Seitenaufteilung, Zeitzonen, Rate-Limits).
      Getestet ist nur gegen einen nachgebauten Server nach der Dawarich-Doku bzw. (Tracks) nach dem Quellcode
      von Dawarich 1.15.2 (`Tracks::GeojsonSerializer`); bitte mit „Dawarich-Verbindung testen"
      und einer Erkennung über ein paar Tage gegenprüfen
- [x] Button „Dawarich-Verbindung testen" (auf der Fahrten-Seite)
- [x] API-Key als Passwortfeld im Profilformular
- [x] Unit-Tests (72 Tests), GitHub Actions: `php -l`, PHPStan Level 6, PHP-CS-Fixer, PHPUnit auf PHP 8.1–8.4, E2E
- [x] Screenshots und README auf Deutsch

## 0.3 — Dawarich richtig nutzen (Kernidee) ✅

- [x] **Automatische Fahrterkennung pro Tag** — inzwischen aus den Dawarich-*Tracks* und ihren
      Transportmodus-Abschnitten (die eigene Stopp-/Bewegungserkennung auf GPS-Punkten ist entfallen);
      Stopp-Dauer und kürzeste Fahrt in den Systemeinstellungen
- [x] **Vorschlagsliste** mit Art und Fahrzeug pro Vorschlag, übernehmen / bearbeiten / verwerfen, „alle
      geschäftlichen übernehmen"; zweiter Arbeitsweg am selben Tag wird zusammengeführt
- [x] **Orte**: eigene Orte und Import der Dawarich-*Areas*; Zuordnung über den Mittelpunkt des Stopps
- [ ] Kundenadresse aus Kimai automatisch als Ort vorschlagen — *teilweise*: Orte können einem Kunden zugeordnet
      werden, beim Erfassen aus einem Zeiteintrag wird der Kunde als Ziel übernommen; Kimai-Kundenadressen werden
      nicht geokodiert
- [x] **Abgleich mit Zeiteinträgen**: Fahrt vor/nach einem Zeiteintrag bekommt Projekt und Kunde
- [x] Rückwärts-Geokodierung (Nominatim/Photon, abschaltbar)
- [x] Kartenvorschau (Leaflet wird mit dem Plugin ausgeliefert, Kachelserver einstellbar/abschaltbar)
- [x] `kimai:bundle:mileage:suggest` für den Cronjob

## 0.4 — Fahrzeuge und Fahrtenbuch ✅

- [x] Fahrzeugverwaltung (Typ, Kennzeichen, Halter, gültig von–bis, mehrere Fahrzeuge pro Nutzer)
- [x] **Mietwagen als Vorgang**: Zeitraum, Anbieter, Miet- und Tankkosten; Fahrten im Zeitraum werden zugeordnet,
      Kosten nach km verteilt
- [x] Kilometerstand Beginn/Ende mit Vorschlag und Lückenprüfung
- [x] Pflichtangaben im Fahrtenbuch (Datum, km-Stand, Ziel, Reisezweck, Geschäftspartner) mit Warnungen
- [x] **Unveränderbarkeit**: Monatsabschluss, Änderungen danach nur mit `edit_locked_mileage`, jede Änderung im
      Protokoll (zentral durchgesetzt, gilt auch für API und Import)
- [x] Belege als Dateianhänge (PDF/Bilder, Inhaltsprüfung, max. 10 MB)

## 0.5 — Steuer vollständig (Fokus Selbstständige) ✅ (ohne Sonderfälle)

- [x] **Steuerprofil pro Nutzer**: Selbstständig (EÜR, Standard) oder Arbeitnehmer (Anlage N)
- [x] **Selbstständig / EÜR**: Fahrzeuge im Betriebsvermögen ohne km-Pauschale; Privatnutzung per 1-%-Regel
      (E-Auto 0,25 %, Plug-in-Hybrid 0,5 %, Listenpreis abgerundet) inkl. 0,03-%-Regel Wohnung–Betrieb, oder
      Privatanteil nach Fahrtenbuch
- [x] **Sätze nach Jahr** (2020–2026 hinterlegt, Einstellung überschreibt nur bei Bedarf)
- [x] Verpflegungsmehraufwand (14 €/28 €), mehrtägige Reisen, Dreimonatsfrist mit 4-Wochen-Unterbrechung.
      *Vereinfacht*: keine Kürzung für gestellte Mahlzeiten, Reiseziele werden über den Namen verglichen
- [ ] Sonderfälle: Sammelpunkt / weiträumiges Tätigkeitsgebiet, Familienheimfahrten (doppelte Haushaltsführung),
      mehrere Tätigkeitsstätten — *offen*, bei Bedarf als Dienstreise/Arbeitsweg manuell zuordnen
- [x] Plausibilitätsprüfung inkl. Urlaub/Krankheit aus dem HolidayBundle (optional, ohne feste Abhängigkeit)
- [x] PDF-Bericht — mit Bezeichnungen der Formularabschnitte statt Zeilennummern (die ändern sich jedes Jahr)
- [x] **Kundenübersicht zur manuellen Übernahme** mit CSV (kein Rechnungsversand)

## 0.6 — Team und Schnittstellen ✅

- [x] Genehmigungs-Workflow (Monat einreichen, genehmigen, mit Begründung ablehnen), Teambericht;
      Teamleitungen sehen nur ihre Teammitglieder
- [x] REST-API `/api/mileage/...` mit Kimai-API-Token
- [x] API für externe Clients (z. B. Plasmai): `ping` mit Rechten, Profil und abgeschlossenen Monaten,
      Zeitraum `from`/`to` für Fahrten und Vorschläge, Übernehmen mit Projekt/km/Bemerkung/Zeiteintrag,
      Zeiteintrag an Fahrten und Vorschlägen
- [x] CSV-Import mit Vorschau und Duplikaterkennung (Web und Konsole)

## 0.11 — Orte: Karte neben der Liste, Flächen, Protomaps, Geokodierung

Entwurf: Design-Canvas „Orte Seitenlayout“ (Knust-Theme, 2026-10-06). Teil A und B bauen aufeinander auf, C und D sind
unabhängig davon. GUI-Regel beachten: alles Fehlende zuerst in Kante (`kimai/knust`, `kimai/kit`) ergänzen,
`../Kante/kimai/kit/bin/sync.sh mileage`, dann hier verwenden; keine eigenen Farben, Maße oder Komponenten.

### A — Seite „Orte“: Layout, Flächen, Hover

- [ ] **Kante zuerst:** Kit-Makro `kpu-split` (Liste + Karte nebeneinander, Karte sticky, Umbruch unter `xl`),
      `kpu-map-area` (Kartenfläche: normal / hervorgehoben / vorläufig gestrichelt) mit Tokens `--knust-map-area-*`,
      Kit-JS für Kreise und Hover-Verknüpfung; danach `sync.sh` und `check-kit.sh`
- [ ] **Layout:** ab `xl` Liste links (ca. 600 px) und Karte rechts, darunter Karte oben und Liste darunter
- [ ] **Karte sticky in beiden Varianten:** breit klebt die Karte oben im Scrollbereich, die Liste scrollt daneben;
      schmal bleibt die Karte oben stehen, die Liste scrollt darunter; Listenkopf klebt ebenfalls; Kartenhöhe unter `xl`
      fest und niedrig, damit die Liste Platz behält
- [ ] **Spalten:** Name, Art, Adresse, Kunde. *Art* nur als Icon (`PlaceType::icon()`, Typname als Tooltip und
      `aria-label`, schmale Spalte); die Spalte *Radius* entfällt, der Radius steht im Popup des Ortes (anzeigen und
      einstellen) und als Fläche auf der Karte
- [ ] **Orte als Flächen:** `L.circle([lat, lon], {radius})` statt nur Punkten (Dawarich-Areas sind Kreise, kein
      Polygon nötig); Pin (`kpu-map-pin`) bleibt als Mittelpunkt; Farbe je Ortstyp aus `--knust-*`, zur Laufzeit gelesen;
      vorläufige Orte gestrichelt; `fitBounds` über die Kreisgrenzen (`circle.getBounds()`), nicht nur die Mittelpunkte;
      `data-markers` wird `[lat, lon, name, radius, type, id]`, die Fahrtenkarte bleibt unverändert
- [ ] **Hover in beide Richtungen:** Zeile hovern hebt die Fläche auf der Karte hervor (dickerer Rand, stärkere Füllung,
      größerer Pin, Name als Label); Fläche hovern hebt die Zeile in der Liste hervor (Hintergrund und Streifen in der
      Typfarbe). Zeilen bekommen `data-place-id`; gleiches Verhalten bei Tastaturfokus auf der Zeile
- [ ] **`scrollIntoView` beim Hover auf der Karte:** liegt die hervorgehobene Zeile außerhalb des sichtbaren Bereichs der
      Liste, wird sie mit `scrollIntoView({block: 'nearest'})` in Sicht gebracht (kein Zentrieren, damit nichts springt).
      Nur bei Hover auf der Karte, nicht beim Hover in der Liste selbst; kurze Verzögerung (ca. 150 ms), damit
      Darüberwischen nicht durch die Liste ruckelt; `scroll-margin-top` für den klebenden Listenkopf (und unter `xl`
      für die klebende Karte), `behavior: 'auto'` bei `prefers-reduced-motion`; Zeile ist beim Scrollen nicht verdeckt
- [ ] **Touch:** ohne Hover hebt ein Tippen auf eine Fläche die Zeile hervor und scrollt sie in Sicht; Tippen auf die
      Zeile hebt die Fläche hervor. Klick auf eine Fläche öffnet wie ein Klick auf die Zeile den Popup des Ortes
      *(zu bestätigen)*
- [ ] **Tests und Abschluss:** E2E-Test (Layout ab `xl`, Karte bleibt beim Scrollen sichtbar, Hover in beide
      Richtungen, `scrollIntoView` bei weggescrollter Zeile), `CHANGELOG.md`; Screenshots erst beim getaggten Release
      neu aufnehmen

### B — Alternative Karten-Integration über Protomaps

- [ ] Einstellung **Kartenquelle**: Rasterkacheln (bisher, Standard) oder **Protomaps-Dienst** (Vektorkacheln mit
      API-Schlüssel); Karte weiterhin abschaltbar, Fallback auf Raster, wenn der Dienst nicht lädt
- [ ] **Entschieden:** nur Protomaps-Dienste mit API-Schlüssel, keine eigenen `.pmtiles`-Dateien (weder ausgeliefert
      noch selbst gehostet). Schlüssel als Passwortfeld in den Systemeinstellungen, nie im Seitenquelltext von Seiten
      ohne Karte und nicht im Protokoll; Hinweis in der Doku, dass Kartenanfragen (Kachelkoordinaten, IP) an den Dienst
      gehen. *Klären:* ob der Schlüssel im Browser sichtbar sein muss (Kacheln werden vom Browser geladen) und ob der
      Dienst Schlüssel auf Domains beschränken kann, sonst Kachel-Proxy im Plugin
- [ ] Anbindung in Leaflet über `protomaps-leaflet` (mit dem Plugin ausgeliefert wie Leaflet, kein CDN);
      `mileage_map_tiles()`/`mileage_map_attribution()` und `_map_script.html.twig` um die Quelle erweitern;
      Kartenstil aus den Knust-Tokens ableiten (hell/dunkel, kein eigenes Farbschema)
- [ ] Gilt für alle Karten (Orte, Fahrt, Vorschläge); Flächen, Routen und Pins aus Teil A bleiben unverändert
- [ ] Attribution (OpenStreetMap und Protomaps) immer sichtbar; Content-Security-Policy (Dienst-Domain) und
      Web-Worker/`blob:` prüfen; Tests für Einstellung, Fehlerfall ohne/mit falschem Schlüssel, Fallback und Attribution

### C — Dawarich als Geocoding-Server

- [ ] **Ist-Stand:** Rückwärts-Geokodierung läuft schon über Dawarich (`/api/v1/places/nearby`), erst danach über das
      optionale Nominatim/Photon (`GeocoderClient`)
- [ ] **Zuerst klären:** welche Dawarich-API (Version der eigenen Instanz) die Vorwärts-Suche (Adresse → Koordinaten)
      anbietet oder ob nur der dort konfigurierte Photon/Nominatim erreichbar ist; nichts annehmen, was die API nicht
      hergibt
- [ ] Einstellung **Geocoding-Server**: Dawarich (Standard, wenn verbunden), eigene Nominatim/Photon-URL oder aus;
      Fehler und Zeitüberschreitung fallen auf die nächste Quelle zurück
- [ ] **Vorwärts-Geokodierung** im Ort-Popup (Adresse suchen, Koordinaten und Radius übernehmen, Fläche in der Vorschau)
      und für **Kundenadressen aus Kimai als Ortsvorschlag** (schließt den offenen Punkt aus 0.3)
- [ ] Zugriff mit dem Dawarich-Schlüssel des jeweiligen Nutzers, Ratenbegrenzung beachten, nur Adresse speichern;
      Tests mit gemocktem `DawarichClient`

### Reihenfolge

1. Kante: `kpu-split`, `kpu-map-area`, Hover-Skript (A, Voraussetzung für alles Weitere auf der Seite)
2. A als Branch und Pull Request auf Gitea; Release danach, dann Screenshots
3. C (Klärung der Dawarich-API, dann Umsetzung), unabhängig von A
4. B (Protomaps-Dienst mit API-Schlüssel)

---

## 1.0 — Veröffentlichung

- [ ] Landingpage via GitHub Pages — *bewusst zurückgestellt*
- [ ] Eintrag im Kimai-Marketplace — *bewusst zurückgestellt*
- [x] Release-Archive: beim Setzen eines Tags `v*` baut GitHub Actions ein installierbares ZIP
- [x] Übersetzungen vollständig (per Test abgesichert), länderspezifisches nur über Sätze/Einstellungen

---

## Priorisierung (nächste Schritte)

1. Mit der eigenen Dawarich-Instanz testen (0.2) — Verbindung testen, eine Woche erkennen lassen, Ergebnisse prüfen.
2. Danach 1.0 (Landingpage, Marketplace) oder die offenen Sonderfälle aus 0.5, je nach Bedarf.
3. Eine echte **Kundenrechnungsstellung** ist bewusst **nicht** Teil dieser Roadmap — das übernimmt Invoice Ninja.
