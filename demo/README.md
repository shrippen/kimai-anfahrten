# Demo (internal)

Internal tool for automated screenshots, not part of any release. Uses the shared demo world of all shrippen projects (shrippen.github.io/demo).

`demo/start.sh [de|en] [default|knust]` startet ein lokales Kimai (Docker) mit diesem Plugin und erfundenen Daten
aus „Studio Weber“, der gemeinsamen Demowelt aller shrippen-Projekte. Das Setup liegt im Nachbar-Checkout
`shrippen.github.io/demo/kimai/`; `demo/seed.php` ergänzt die Plugin-Daten, `demo/world.json` und
`demo/DemoWorld.php` sind Kopien von dort (`shrippen.github.io/demo/tools/sync-demo.py`, nicht hier bearbeiten). Anmeldung:
`mara` / `demo-password-1`. `demo/shots.json` beschreibt die Screenshots für `docs/shots/`
(`shrippen.github.io/demo/tools/screenshots.py`).
