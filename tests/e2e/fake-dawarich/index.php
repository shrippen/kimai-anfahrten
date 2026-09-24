<?php

/*
 * Minimal fake of the Dawarich API for end-to-end tests (points, areas, tracks with
 * transportation modes). Every weekday: home → office (car), a walk to lunch and back,
 * office → customer → home (car), and an evening bike ride.
 */
header('Content-Type: application/json');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer test-key') {
    http_response_code(401);
    echo '{"error":"unauthorized"}';

    return;
}
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

const HOME = [52.5200, 13.4050];
const OFFICE = [52.5000, 13.3000];
const CAFE = [52.5000, 13.3295];    // 2 km east of the office
const CUSTOMER = [52.4000, 13.0600];
const LAKE = [52.5200, 13.5230];    // 8 km east of home

if ($path === '/api/v1/areas') {
    echo json_encode([
        ['id' => 1, 'name' => 'Zuhause', 'latitude' => HOME[0], 'longitude' => HOME[1], 'radius' => 150],
        ['id' => 2, 'name' => 'Büro', 'latitude' => OFFICE[0], 'longitude' => OFFICE[1], 'radius' => 150],
    ]);

    return;
}

/**
 * @return array{points: list<array{0: array{float, float}, 1: int}>, segments: list<array{mode: string, start_time: int, end_time: int}>}
 */
function day(DateTimeImmutable $d): array
{
    $points = [];
    $segments = [];
    $t = fn (string $hm) => $d->modify($hm)->getTimestamp();
    $stay = function (array $at, string $a, string $b) use (&$points, $t) {
        for ($x = $t($a); $x <= $t($b); $x += 300) {
            $points[] = [$at, $x];
        }
    };
    $move = function (array $p, array $q, string $a, string $b, string $mode) use (&$points, &$segments, $t) {
        $from = $t($a);
        $to = $t($b);
        $n = max(2, intdiv($to - $from, 30));
        for ($i = 1; $i < $n; $i++) {
            $f = $i / $n;
            $points[] = [[$p[0] + ($q[0] - $p[0]) * $f, $p[1] + ($q[1] - $p[1]) * $f], $from + (int) (($to - $from) * $f)];
        }
        $segments[] = ['mode' => $mode, 'start_time' => $from, 'end_time' => $to];
    };

    $stay(HOME, '06:00', '07:30');
    $move(HOME, OFFICE, '07:30', '07:50', 'driving');
    $stay(OFFICE, '07:50', '10:00');
    $move(OFFICE, CAFE, '10:00', '10:25', 'walking');
    $stay(CAFE, '10:25', '10:40');
    $move(CAFE, OFFICE, '10:40', '11:05', 'walking');
    $stay(OFFICE, '11:05', '12:00');
    $move(OFFICE, CUSTOMER, '12:00', '12:35', 'driving');
    $stay(CUSTOMER, '12:35', '16:00');
    $move(CUSTOMER, HOME, '16:00', '16:45', 'driving');
    $stay(HOME, '16:45', '18:00');
    $move(HOME, LAKE, '18:00', '18:40', 'cycling');
    $stay(LAKE, '18:40', '19:10');
    $move(LAKE, HOME, '19:10', '19:50', 'cycling');
    $stay(HOME, '19:50', '22:00');

    return ['points' => $points, 'segments' => $segments];
}

/**
 * @return iterable<DateTimeImmutable>
 */
function weekdays(int $from, int $to): iterable
{
    $tz = new DateTimeZone('Europe/Berlin');
    for ($d = (new DateTimeImmutable('@' . $from))->setTimezone($tz)->setTime(0, 0); $d->getTimestamp() < $to; $d = $d->modify('+1 day')) {
        if ((int) $d->format('N') <= 5) {
            yield $d;
        }
    }
}

// tracks: one track per weekday, the id is the date (Ymd)
if ($path === '/api/v1/tracks') {
    $features = [];
    foreach (weekdays(strtotime($_GET['start_at']), strtotime($_GET['end_at'])) as $d) {
        $features[] = ['type' => 'Feature', 'properties' => ['id' => (int) $d->format('Ymd'), 'dominant_mode' => 'driving']];
    }
    echo json_encode(['type' => 'FeatureCollection', 'features' => $features]);

    return;
}
if (preg_match('#^/api/v1/tracks/(\d{8})$#', $path, $m)) {
    $d = new DateTimeImmutable($m[1], new DateTimeZone('Europe/Berlin'));
    echo json_encode(['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => ['id' => (int) $m[1], 'segments' => day($d)['segments']]]]]);

    return;
}

if ($path !== '/api/v1/points') {
    http_response_code(404);
    echo '[]';

    return;
}

$from = strtotime($_GET['start_at']);
$to = strtotime($_GET['end_at']);
$points = [];
foreach (weekdays($from, $to) as $d) {
    array_push($points, ...day($d)['points']);
}
$points = array_values(array_filter($points, fn ($p) => $p[1] >= $from && $p[1] <= $to));
$rows = array_map(fn ($p) => ['latitude' => (string) $p[0][0], 'longitude' => (string) $p[0][1], 'timestamp' => $p[1], 'accuracy' => 8], $points);
$per = (int) ($_GET['per_page'] ?? 100);
$page = (int) ($_GET['page'] ?? 1);
header('X-Total-Pages: ' . max(1, (int) ceil(count($rows) / $per)));
header('X-Current-Page: ' . $page);
echo json_encode(array_slice($rows, ($page - 1) * $per, $per));
