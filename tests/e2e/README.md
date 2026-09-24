# End-to-end tests

Browser tests of the plugin inside a real Kimai with a fake Dawarich API
(`fake-dawarich/index.php` serves a Monday-to-Friday day: home → office → customer → home).

They run in CI on every push (see `.github/workflows/ci.yml`, job *Kimai end-to-end*).

## Run locally

```bash
git clone --depth 1 --branch 2.67.0 https://github.com/kimai/kimai.git /tmp/kimai
cd /tmp/kimai && composer install --no-dev -o
# database (it is dropped and re-created by the test run!)
echo 'DATABASE_URL=mysql://kimai:kimai@127.0.0.1:3306/kimai_e2e?charset=utf8mb4&serverVersion=10.11.0-MariaDB' > .env.local
echo 'APP_ENV=prod' >> .env.local
npm install -g playwright && npx playwright install chromium
/path/to/kimai-anfahrten/tests/e2e/run.sh /tmp/kimai
```

Optional: put the HolidayBundle into `/tmp/kimai/var/plugins/HolidayBundle` to include the
absence check. Screenshots and server logs end up in `tests/e2e/screenshots/`.
