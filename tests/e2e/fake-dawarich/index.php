<?php

// Minimal fake of the Dawarich API for end-to-end tests.
header('Content-Type: application/json');
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth !== 'Bearer test-key') {
    http_response_code(401);
    echo '{"error":"unauthorized"}';

    return;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);


const HOME = [52.5200, 13.4050];
const OFFICE = [52.5000, 13.3000];
const CUSTOMER = [52.4000, 13.0600];

if ($path === '/api/v1/areas') {
    echo json_encode([
        ['id' => 1, 'name' => 'Zuhause', 'latitude' => HOME[0], 'longitude' => HOME[1], 'radius' => 150],
        ['id' => 2, 'name' => 'Büro', 'latitude' => OFFICE[0], 'longitude' => OFFICE[1], 'radius' => 150],
    ]);

    return;
}
if ($path !== '/api/v1/points') {
    http_response_code(404);
    echo '[]';

    return;
}

$from = strtotime($_GET['start_at']);
$to = strtotime($_GET['end_at']);
$tz = new DateTimeZone('Europe/Berlin');
$points = [];
// every weekday in the window gets the same pattern
for ($d = (new DateTimeImmutable('@' . $from))->setTimezone($tz)->setTime(0, 0); $d->getTimestamp() < $to; $d = $d->modify('+1 day')) {
    if ((int) $d->format('N') > 5) {
        continue;
    }
    $t = fn (string $hm) => $d->modify($hm)->getTimestamp();
    $stay = function (array $at, int $a, int $b) use (&$points) {
        for ($x = $a; $x <= $b; $x += 300) {
            $points[] = [$at, $x];
        }
    };
    $drive = function (array $p, array $q, int $a, int $b) use (&$points) {
        $n = max(2, intdiv($b - $a, 30));
        for ($i = 1; $i < $n; $i++) {
            $f = $i / $n;
            $points[] = [[$p[0] + ($q[0] - $p[0]) * $f, $p[1] + ($q[1] - $p[1]) * $f], $a + (int) (($b - $a) * $f)];
        }
    };
    $stay(HOME, $t('06:00'), $t('07:30'));
    $drive(HOME, OFFICE, $t('07:30'), $t('07:50'));
    $stay(OFFICE, $t('07:50'), $t('12:00'));
    $drive(OFFICE, CUSTOMER, $t('12:00'), $t('12:35'));
    $stay(CUSTOMER, $t('12:35'), $t('16:00'));
    $drive(CUSTOMER, HOME, $t('16:00'), $t('16:45'));
    $stay(HOME, $t('16:45'), $t('22:00'));
}
$points = array_values(array_filter($points, fn ($p) => $p[1] >= $from && $p[1] <= $to));
$rows = array_map(fn ($p) => ['latitude' => (string) $p[0][0], 'longitude' => (string) $p[0][1], 'timestamp' => $p[1], 'accuracy' => 8], $points);
$per = (int) ($_GET['per_page'] ?? 100);
$page = (int) ($_GET['page'] ?? 1);
header('X-Total-Pages: ' . max(1, (int) ceil(count($rows) / $per)));
header('X-Current-Page: ' . $page);
echo json_encode(array_slice($rows, ($page - 1) * $per, $per));
