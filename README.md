# Mileage & Trips (Fahrten)

Open-source Kimai plugin to record trips — commutes, business trips and private trips — with optional
[Dawarich](https://dawarich.app) integration to measure the driven distance from your GPS history, and a
yearly summary for the German tax return (Entfernungspauschale + Reisekosten, Anlage N).

**Requires [Kimai](https://www.kimai.org/) ≥ 2.64.**

> **Status:** early draft / not yet tested against a running Kimai. Tax rates are defaults for Germany 2026 and can be changed in the system settings. This is a calculation aid, not tax advice.

| | |
|---|---|
| **Kimai plugin id** | `MileageBundle` |
| **License** | [GPL-3.0-or-later](LICENSE) |
| **PHP** | ≥ 8.1 |

## Features

- **Fahrten** (menu under Zeiterfassung): list per month/year, add / edit / duplicate / delete
- Trip type: **Arbeitsweg** (commute), **Dienstreise** (business), **Privat**
- Vehicle: **eigener PKW**, **Mietwagen**, **Firmenwagen**, Motorrad/Roller, Fahrrad, Bahn/ÖPNV, Sonstiges — plus license plate
- Actual costs per trip (rental car incl. fuel, tickets, …)
- **Dawarich**: enter departure/arrival, click *Strecke aus Dawarich berechnen* — GPS points of that window are loaded
  (`GET /api/v1/points`), inaccurate points and GPS jumps are filtered, the distance is summed up
- **Arbeitswege aus Arbeitszeiten**: suggests a commute for every day of a month with timesheets; untick home-office days
- **Fahrt erfassen** action on every timesheet row (prefills date, project, customer, time window)
- **Fahrtkosten (Steuer)** report per year:
  - Entfernungspauschale: once per workday, one-way distance in full km, 4,500 € cap for non-car commutes,
    higher actual public transport costs are used if applicable
  - Dienstreisen: own car / motorcycle with per-km rate; rental car, public transport, bicycle with actual costs; company car not deductible
- CSV export (logbook style, Excel-friendly)
- Per-user preferences (Profil → Einstellungen): Dawarich URL + API key, home address, place of work, commute distance, default vehicle, license plate

### Tax defaults (Germany, 2026)

| Setting | Default |
|---|---|
| Entfernungspauschale | 0.38 €/km from the first km |
| Business trip, own car | 0.30 €/km |
| Business trip, motorcycle/scooter | 0.20 €/km |
| Cap for commutes without car | 4,500 €/year |

Rental car vs. own car: for **commutes** the vehicle does not change the Entfernungspauschale (only the cap).
For **business trips** an own car gets the per-km rate, a rental car is deducted with its actual costs (enter them in *Kosten*).

## Installation

```bash
cd /path/to/kimai/var/plugins
git clone https://github.com/shrippen/kimai-mileage-bundle.git MileageBundle
bin/console kimai:reload -n
bin/console kimai:bundle:mileage:install
```

Docker (official image):

```bash
docker exec -it CONTAINER /opt/kimai/bin/console kimai:reload -n
docker exec -it CONTAINER /opt/kimai/bin/console kimai:bundle:mileage:install
```

Then assign permissions under **System → Roles** (section *Fahrten*).

## Dawarich setup

1. In Dawarich: *Account* → copy your **API key**.
2. In Kimai: *Profil → Einstellungen* → **Dawarich-URL** (or set a default under *System → Einstellungen*) and **Dawarich-API-Key**.
3. Add a trip, enter departure and arrival time, click **Strecke aus Dawarich berechnen**, check the value, save.

Kimai must be able to reach your Dawarich instance over HTTP(S).

## Permissions

| Permission | Purpose |
|---|---|
| `mileage` | Access trips and tax report |
| `edit_own_mileage` / `edit_other_mileage` | Create and edit trips |
| `delete_own_mileage` / `delete_other_mileage` | Delete trips |
| `view_other_mileage` | View other users' trips (`?user=ID`) |

## Roadmap / ideas

- Detect trips automatically from Dawarich (visits / tracks) and suggest them per day
- Vehicle entity (several cars, odometer readings for a proper Fahrtenbuch)
- Verpflegungsmehraufwand (per diem) for business trips
- PDF export, REST API

## License

GPL-3.0-or-later — see [LICENSE](LICENSE).
