// End-to-end tests of the MileageBundle inside a real Kimai (see tests/e2e/README.md).
const { chromium } = require('playwright');
const path = require('path');

const BASE = process.env.KIMAI_URL || 'http://127.0.0.1:8001';
const SHOTS = process.env.SHOTS || path.join(__dirname, 'screenshots');
const PASSWORDS = { admin: 'Admin12345!', hans: 'Hans12345!', tina: 'Tina12345!' };

let failures = 0;
const check = (ok, message) => {
  console.log((ok ? '  ✓ ' : '  ✗ ') + message);
  if (!ok) failures++;
};
const section = (title) => console.log('\n' + title);

async function login(browser, user) {
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 }, locale: 'de-DE', timezoneId: 'Europe/Berlin' });
  page.on('pageerror', (e) => check(false, 'JS error: ' + e.message));
  page.on('response', (r) => { if (r.status() >= 500) check(false, 'HTTP ' + r.status() + ' ' + r.url()); });
  await page.goto(BASE + '/de/login');
  await page.fill('#username', user);
  await page.fill('#password', PASSWORDS[user]);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  return page;
}
const submit = (page, selector) => Promise.all([page.waitForNavigation(), page.click(selector)]);
const texts = async (page, selector) => (await page.locator(selector).allInnerTexts()).map((s) => s.replace(/\s+/g, ' ').trim());
const api = (user, url, options = {}) => fetch(BASE + url, { ...options, headers: { Authorization: `Bearer e2e-token-${user}-0123456789`, 'Content-Type': 'application/json', ...(options.headers || {}) } });

(async () => {
  const browser = await chromium.launch();
  const ids = {};

  section('Smoke: every page renders');
  let page = await login(browser, 'admin');
  for (const url of ['/de/mileage/', '/de/mileage/2026/9', '/de/mileage/trip/create', '/de/mileage/trip/create?purpose=commute', '/de/mileage/commutes/2026/9',
    '/de/mileage/suggestions', '/de/mileage/places', '/de/mileage/vehicles', '/de/mileage/vehicles/create', '/de/mileage/rentals', '/de/mileage/rentals/create',
    '/de/mileage/months', '/de/mileage/history', '/de/mileage/overview', '/de/mileage/import', '/de/mileage/team', '/de/mileage/tax/2026',
    '/de/profile/admin/prefs', '/de/admin/system-config/', '/de/timesheet/']) {
    const r = await page.goto(BASE + url);
    check(r.status() === 200, `${url} → ${r.status()}`);
  }
  await page.goto(BASE + '/de/profile/admin/prefs');
  check((await page.content()).includes('mileage_tax_profile'), 'tax profile preference rendered');

  section('Dawarich: places and trip detection');
  await page.goto(BASE + '/de/mileage/places');
  await submit(page, 'form[action*="places/import"] button');
  check((await texts(page, 'tbody tr td:first-child')).join() === 'Zuhause Dawarich,Büro Dawarich', 'Dawarich areas imported as places');
  await page.goto(BASE + '/de/mileage/suggestions');
  await page.fill('#detect-from', '2026-09-21');
  await page.fill('#detect-to', '2026-09-22');
  await submit(page, 'form[action*="detect"] button');
  let rows = await texts(page, 'tbody tr');
  check(rows.length === 6, `6 trips detected on two weekdays (${rows.length})`);
  check(rows[0].includes('07:30') && rows[0].includes('Zuhause') && rows[0].includes('Büro'), 'home → office recognised with local time');
  check(await page.locator('tbody tr').first().locator('select[name=purpose]').inputValue() === 'commute', 'home ↔ office suggested as commute');
  check(rows[1].includes('ACME GmbH / Relaunch'), 'trip matched to timesheet project');
  await page.screenshot({ path: SHOTS + '/suggestions.png', fullPage: true });
  await submit(page, 'tbody tr:first-child button.btn-success');
  await submit(page, 'tbody tr:first-child button[name=edit]');
  check(await page.inputValue('#trip_form_departureAt') === '2026-09-21T12:00', 'edit form shows local departure time');
  check(await page.locator('#trip-map').count() === 1, 'map preview present');
  await page.goto(BASE + '/de/mileage/2026/9');
  rows = await texts(page, 'tbody tr');
  check(rows.some((r) => r.includes('Arbeitsweg') && r.includes('16,0 km')), 'accepted commute uses stored distance (8 km each way)');
  await page.screenshot({ path: SHOTS + '/trips.png', fullPage: true });

  section('Vehicles, rentals, receipts, logbook');
  await page.goto(BASE + '/de/mileage/vehicles/create');
  await page.fill('#vehicle_form_name', 'Golf');
  await page.fill('#vehicle_form_licensePlate', 'B-GO 42');
  await page.fill('#vehicle_form_initialOdometer', '10000');
  await submit(page, 'form[name=vehicle_form] button[type=submit]');
  ids.vehicle = (await page.locator('a[href*="/mileage/logbook/"]').first().getAttribute('href')).match(/logbook\/(\d+)/)[1];
  await page.goto(BASE + '/de/mileage/trip/create');
  check((await page.locator('#trip_form_assignedVehicle option:checked').innerText()) === 'Golf (B-GO 42)', 'only vehicle is preselected');
  await page.fill('#trip_form_date', '02.09.2026');
  await page.fill('#trip_form_destination', 'Kunde A');
  await page.fill('#trip_form_distanceKm', '42');
  await page.fill('#trip_form_comment', 'Workshop');
  await submit(page, '#trip_form_save');
  await page.goto(BASE + '/de/mileage/rentals/create');
  await page.fill('#rental_form_provider', 'Sixt');
  await page.fill('#rental_form_licensePlate', 'M-SX 1');
  await page.fill('#rental_form_startDate', '10.09.2026');
  await page.fill('#rental_form_endDate', '12.09.2026');
  await page.fill('#rental_form_rentalCosts', '150');
  await page.fill('#rental_form_fuelCosts', '50');
  await submit(page, 'form[name=rental_form] button[type=submit]');
  const rentalUrl = page.url();
  ids.rental = rentalUrl.match(/rentals\/(\d+)/)[1];
  for (const [date, purpose, km] of [['10.09.2026', 'business', '300'], ['11.09.2026', 'private', '100'], ['30.09.2026', 'business', '12']]) {
    await page.goto(BASE + '/de/mileage/trip/create');
    await page.selectOption('#trip_form_assignedVehicle', '');
    await page.fill('#trip_form_date', date);
    await page.check(`input[name="trip_form[purpose]"][value=${purpose}]`);
    await page.selectOption('#trip_form_vehicle', date === '30.09.2026' ? 'own_car' : 'rental_car');
    await page.fill('#trip_form_destination', 'Hamburg');
    await page.fill('#trip_form_distanceKm', km);
    await submit(page, '#trip_form_save');
  }
  await page.goto(rentalUrl);
  const rental = (await texts(page, '.col-lg-4 .card-body')).join(' ');
  check(rental.includes('Davon Dienstreisen (abziehbar): 150,00 €'), 'rental costs shared by km (300 of 400 km → 150 €)');
  check((await texts(page, 'tbody tr')).length === 2, 'rental trips linked including the first day');
  await page.goto(BASE + '/de/mileage/2026/9');
  check((await texts(page, 'tbody tr')).some((r) => r.startsWith('2026-09-30')), 'trip on the last day of the month is listed');
  const edit = await page.locator('a[href*="/mileage/trip/"][href$="/edit"]').last().getAttribute('href');
  ids.trip = edit.match(/trip\/(\d+)/)[1];
  await page.goto(BASE + '/de/mileage/logbook/' + ids.vehicle + '/2026');
  check((await texts(page, 'table tbody tr')).some((r) => r.includes('10000') && r.includes('10042')), 'odometer suggested from vehicle (10000 → 10042)');
  const csv = await page.request.get(page.url() + '?format=csv');
  check(csv.status() === 200 && (await csv.text()).includes('10042'), 'logbook CSV export');
  await page.goto(BASE + edit);
  await page.setInputFiles('input[type=file][name=file]', path.join(__dirname, 'receipt.pdf'));
  await submit(page, 'form[enctype="multipart/form-data"] button');
  const attachment = await page.locator('a[href*="/mileage/attachments/"]').first().getAttribute('href');
  ids.attachment = attachment.match(/attachments\/(\d+)/)[1];
  const download = await page.request.get(BASE + attachment);
  check(download.status() === 200 && download.headers()['content-type'] === 'application/pdf', 'receipt upload and download');

  section('Tax report');
  await page.goto(BASE + '/de/mileage/tax/2026');
  const findings = (await texts(page, '.list-group-item')).join(' | ');
  check(findings.includes('Dienstreisen ohne Uhrzeiten'), 'plausibility check runs');
  const pdf = await page.request.get(BASE + '/de/mileage/tax/2026?format=pdf');
  check(pdf.status() === 200 && (await pdf.body()).slice(0, 5).toString() === '%PDF-', 'PDF export');
  await page.screenshot({ path: SHOTS + '/tax.png', fullPage: true });
  await page.goto(BASE + '/de/mileage/tax/2026?profile=employee');
  check((await texts(page, '.card-title')).some((t) => t.startsWith('Entfernungspauschale')), 'employee profile uses Anlage N wording');

  section('Month closing and audit log');
  await page.goto(BASE + '/de/mileage/months/2026');
  page.once('dialog', (d) => d.accept());
  await submit(page, 'form[action*="/months/2026/9/lock"] button');
  // approval is enabled in the seed, so closing means handing in
  check((await texts(page, 'tbody tr')).some((r) => r.startsWith('09/2026') && r.includes('Zur Freigabe eingereicht')), 'September handed in (locked)');
  await page.goto(BASE + edit);
  await page.fill('#trip_form_comment', 'korrigiert');
  await submit(page, '#trip_form_save');
  await page.goto(BASE + '/de/mileage/history');
  check((await texts(page, 'tbody tr'))[0].includes('comment'), 'admin change in closed month is logged');
  await page.close();

  section('REST API');
  let r = await api('hans', '/api/mileage/trips', { method: 'POST', body: JSON.stringify({ date: '2026-08-14', distanceKm: 12.5, destination: 'Kunde API', departure: '08:00', arrival: '17:15' }) });
  check(r.status === 201, 'create trip → 201');
  const created = await r.json();
  check(created.departure === '2026-08-14T08:00:00+02:00', 'times interpreted in user timezone');
  r = await api('hans', '/api/mileage/trips', { method: 'POST', body: JSON.stringify({ purpose: 'urlaub' }) });
  check(r.status === 400, 'invalid payload → 400');
  r = await api('hans', `/api/mileage/trips/${created.id}`, { method: 'PATCH', body: JSON.stringify({ costs: 4.5 }) });
  check(r.status === 200 && (await r.json()).costs === 4.5, 'patch trip');
  check((await api('hans', '/api/mileage/trips?user=1')).status === 403, 'foreign user → 403');
  check((await fetch(BASE + '/api/mileage/trips')).status === 401, 'no token → 401');
  check((await api('hans', '/api/mileage/tax/2026')).status === 200, 'tax summary');

  section('CSV import (hans)');
  page = await login(browser, 'hans');
  await page.goto(BASE + '/de/mileage/import');
  await page.setInputFiles('input[name=file]', path.join(__dirname, 'import.csv'));
  await submit(page, 'form[enctype] button[type=submit]');
  check((await texts(page, '.card-header .badge')).join() === '2 bereit,0 mögliche Duplikate,1 fehlerhaft', 'import preview');
  await submit(page, 'form:has(input[value=import]) button[type=submit]');
  check(page.url().includes('/de/mileage'), 'import done');

  section('Approval workflow');
  await page.goto(BASE + '/de/mileage/months/2026');
  page.once('dialog', (d) => d.accept());
  await submit(page, 'form[action*="/months/2026/8/lock"] button');
  check((await texts(page, 'tbody tr')).some((x) => x.startsWith('08/2026') && x.includes('Zur Freigabe eingereicht')), 'hans submits August');
  await page.close();
  page = await login(browser, 'tina');
  await page.goto(BASE + '/de/mileage/team/2026/8');
  check((await texts(page, 'table tbody tr')).every((x) => !x.startsWith('admin')), 'team lead only sees her team');
  await page.click('.border-warning button[value=reject]');
  check(await page.locator('.border-warning input[name=comment]').evaluate((el) => !el.checkValidity()), 'rejection needs a reason');
  await page.fill('.border-warning input[name=comment]', 'Bitte Anlass ergänzen');
  await submit(page, '.border-warning button[value=reject]');
  await page.close();
  page = await login(browser, 'hans');
  await page.goto(BASE + '/de/mileage/months/2026');
  check((await texts(page, 'tbody tr')).some((x) => x.includes('Zurückgewiesen') && x.includes('Bitte Anlass ergänzen')), 'hans sees the rejection reason');
  page.once('dialog', (d) => d.accept());
  await submit(page, 'form[action*="/months/2026/8/lock"] button');
  await page.close();
  page = await login(browser, 'tina');
  await page.goto(BASE + '/de/mileage/team/2026/8');
  await submit(page, '.border-warning button[value=approve]');
  check((await texts(page, 'table tbody tr')).some((x) => x.startsWith('hans') && x.includes('Freigegeben')), 'team lead approves');
  await page.screenshot({ path: SHOTS + '/team.png', fullPage: true });
  await page.close();

  section('Authorization');
  const expectations = [
    ['tina', '/de/mileage/?user=' + created.user, 200],
    ['tina', '/de/mileage/?user=1', 403],
    ['tina', '/de/mileage/trip/create?user=' + created.user, 403],
    ['hans', '/de/mileage/2026/8', 200],
    ['hans', '/de/mileage/?user=1', 403],
    ['hans', '/de/mileage/?user=999', 403],
    ['hans', `/de/mileage/logbook/${ids.vehicle}/2026`, 403],
    ['hans', `/de/mileage/rentals/${ids.rental}`, 403],
    ['hans', `/de/mileage/attachments/${ids.attachment}`, 403],
    ['hans', `/de/mileage/trip/${ids.trip}/edit`, 403],
    ['hans', '/de/mileage/team', 403],
  ];
  let current = null;
  for (const [user, url, expected] of expectations) {
    if (current !== user) { if (page) await page.close(); page = await login(browser, user); current = user; }
    const res = await page.goto(BASE + url);
    check(res.status() === expected, `${user} ${url} → ${res.status()} (expected ${expected})`);
  }
  // approved month is locked for hans
  await page.goto(BASE + '/de/mileage/2026/8');
  check(await page.locator('a[href*="/mileage/trip/"][href$="/edit"]').count() === 0, 'approved month cannot be edited');

  await browser.close();
  console.log(failures === 0 ? '\nAll end-to-end checks passed.' : `\n${failures} end-to-end check(s) FAILED.`);
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => { console.error(e); process.exit(2); });
