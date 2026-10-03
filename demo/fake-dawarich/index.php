<?php

/*
 * Demo copy of tests/e2e/fake-dawarich for the Studio Weber demo world (shrippen demo), moved to Hamburg:
 * home in Othmarschen, the studio in Ottensen, lunch at the fish market, the Speiche workshop as customer,
 * and an evening ride to the Elbe beach. Served by demo/after-seed.sh inside the Kimai container.
 *
 * Minimal fake of the Dawarich API for end-to-end tests: areas, places, reverse geocoding (places/nearby), visits and
 * tracks with transportation-mode segments, in the shape of Dawarich's API controllers and serializers (Dawarich 1.15.2).
 * Every weekday is one track (the phone records all day, Dawarich only starts a new track after a 30-minute gap):
 * home → office (car), a walk to lunch and back, office → customer → home (car), and an evening bike ride, with
 * standstills in between.
 */
header('Content-Type: application/json');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer demo-key') {
    http_response_code(401);
    echo '{"error":"unauthorized"}';

    return;
}
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// The day, its places and visits come from the world (travel: location.day).
require __DIR__ . '/../DemoWorld.php';
$world = new DemoWorld(getenv('DEMO_LANG') ?: 'de', __DIR__ . '/../world.json');
$day = $world->data['location']['day'];
$places = array_column($world->data['places'], null, 'id');
$at = static fn (string $id): array => [$places[$id]['lat'], $places[$id]['lon']];
define('ZONE', $world->data['timezone']);

if ($path === '/api/v1/areas') {
    echo json_encode(array_map(static fn (array $a) => ['id' => $a['id'], 'name' => $world->t($places[$a['place']]['name']),
        'latitude' => $places[$a['place']]['lat'], 'longitude' => $places[$a['place']]['lon'], 'radius' => $a['radius']], $day['areas']));

    return;
}

// places (Api::V1::PlacesController#serialize_place)
if ($path === '/api/v1/places') {
    echo json_encode(array_map(static fn (array $p) => ['id' => $p['id'], 'name' => $world->t($places[$p['place']]['name']),
        'latitude' => $places[$p['place']]['lat'], 'longitude' => $places[$p['place']]['lon'], 'source' => 'manual', 'note' => null,
        'icon' => null, 'color' => null, 'visits_count' => $p['visits'], 'name_locked' => true, 'created_at' => '2026-01-01T00:00:00Z', 'tags' => []],
        $day['suggested']));

    return;
}

// reverse geocoding (Places::NearbySearch → Places::PhotonResultFormatter); empty without a geocoder
if ($path === '/api/v1/places/nearby') {
    $here = [(float) $_GET['latitude'], (float) $_GET['longitude']];
    $radius = 1000 * (float) ($_GET['radius'] ?? 0.5);
    $found = [];
    foreach ($day['geocoded'] as $id) {
        // "Kranichweg 12, 22765 Hamburg" → street, number, postcode, city
        preg_match('/^(.*?)\s*(\d+\w*)?,\s*(\d{5})\s+(.+)$/u', $places[$id]['address'], $m);
        if (metres($at($id), $here) <= $radius) {
            $found[] = ['id' => null, 'name' => $world->t($places[$id]['name']), 'latitude' => $places[$id]['lat'], 'longitude' => $places[$id]['lon'],
                'osm_id' => null, 'osm_type' => null, 'osm_key' => null, 'osm_value' => null, 'city' => $m[4], 'country' => 'Germany',
                'street' => $m[1], 'housenumber' => $m[2] ?: null, 'postcode' => $m[3], 'source' => 'photon', 'geodata' => []];
        }
    }
    echo json_encode(['places' => array_slice($found, 0, (int) ($_GET['limit'] ?? 10))]);

    return;
}

function metres(array $a, array $b): float
{
    $dLat = deg2rad($b[0] - $a[0]);
    $dLon = deg2rad($b[1] - $a[1]);
    $h = sin($dLat / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($dLon / 2) ** 2;

    return 2 * 6371008.8 * asin(min(1.0, sqrt($h)));
}

/**
 * The track of one weekday.
 *
 * @return array{start: int, end: int, segments: list<array<string, mixed>>, coordinates: list<array{float, float}>}
 */
function track(DateTimeImmutable $d): array
{
    $segments = [];
    $t = fn (string $hm) => $d->modify($hm)->getTimestamp();
    $add = function (array $p, array $q, string $a, string $b, string $mode) use (&$segments, $t) {
        $from = $t($a);
        $to = $t($b);
        $step = $mode === 'stationary' ? 300 : 30;
        $n = max(2, intdiv($to - $from, $step));
        $coordinates = [];
        $metres = 0.0;
        for ($i = 0; $i <= $n; $i++) {
            $f = $i / $n;
            $point = [$p[0] + ($q[0] - $p[0]) * $f, $p[1] + ($q[1] - $p[1]) * $f];
            if ($coordinates !== []) {
                $last = end($coordinates);
                $metres += metres([$last[1], $last[0]], $point);
            }
            $coordinates[] = [round($point[1], 6), round($point[0], 6)];
        }
        $segments[] = [
            'id' => count($segments) + 1,
            'mode' => $mode,
            'emoji' => '',
            'color' => '#6366F1',
            'start_index' => null,
            'end_index' => null,
            'coordinates' => $coordinates,
            'distance' => (int) round($metres),
            'duration' => $to - $from,
            'avg_speed' => round($metres / max(1, $to - $from) * 3.6, 2),
            'confidence' => 'high',
            'start_time' => $from,
            'end_time' => $to,
        ];
    };

    global $day, $at;
    foreach ($day['segments'] as [$p, $q, $a, $b, $mode]) {
        $add($at($p), $at($q), $a, $b, $mode);
    }
    $first = $day['segments'][0][2];
    $last = end($day['segments'])[3];

    $coordinates = [];
    foreach ($segments as $segment) {
        array_push($coordinates, ...$segment['coordinates']);
    }

    return ['start' => $t($first), 'end' => $t($last), 'segments' => $segments, 'coordinates' => $coordinates];
}

/**
 * GeoJSON feature like Tracks::GeojsonSerializer (segments only in the show action).
 *
 * @return array<string, mixed>
 */
function feature(int $id, array $day, bool $withSegments): array
{
    $distance = array_sum(array_column($day['segments'], 'distance'));
    $properties = [
        'id' => $id,
        'color' => '#6366F1',
        'start_at' => gmdate('Y-m-d\TH:i:s\Z', $day['start']),
        'end_at' => gmdate('Y-m-d\TH:i:s\Z', $day['end']),
        'distance' => $distance,
        'avg_speed' => round($distance / ($day['end'] - $day['start']) * 3.6, 2),
        'duration' => $day['end'] - $day['start'],
        'revision' => 0,
        'dominant_mode' => 'driving',
        'dominant_mode_emoji' => '🚗',
        'mode_timeline' => array_map(fn ($s) => ['start_time' => $s['start_time'], 'end_time' => $s['end_time'], 'emoji' => ''], $day['segments']),
    ];
    if ($withSegments) {
        $properties['segments'] = $day['segments'];
    }

    return ['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => $day['coordinates']], 'properties' => $properties];
}

/**
 * @return iterable<DateTimeImmutable>
 */
function weekdays(int $from, int $to): iterable
{
    $tz = new DateTimeZone(ZONE);
    for ($d = (new DateTimeImmutable('@' . $from))->setTimezone($tz)->setTime(0, 0); $d->getTimestamp() < $to; $d = $d->modify('+1 day')) {
        if ((int) $d->format('N') <= 5) {
            yield $d;
        }
    }
}

// visits (Api::VisitSerializer, Visits::FindInTime: started in the window): office and customer on weekdays
if ($path === '/api/v1/visits') {
    $from = strtotime($_GET['start_at']);
    $to = strtotime($_GET['end_at']);
    $visits = [];
    foreach (weekdays($from - 86400, $to) as $d) {
        foreach ($day['visits'] as [$id, $a, $b]) {
            [$point, $name] = [$at($id), $world->t($places[$id]['name'])];
            $start = $d->modify($a);
            if ($start->getTimestamp() >= $from && $start->getTimestamp() <= $to) {
                $visits[] = ['id' => (int) $d->format('md') * 10 + count($visits), 'area_id' => null, 'user_id' => 1,
                    'started_at' => $start->format('Y-m-d\\TH:i:s.vP'), 'ended_at' => $d->modify($b)->format('Y-m-d\\TH:i:s.vP'),
                    'duration' => ($d->modify($b)->getTimestamp() - $start->getTimestamp()) / 60, 'name' => $name, 'status' => 'confirmed',
                    'confidence' => 90, 'confidence_band' => 'high', 'place' => ['latitude' => $point[0], 'longitude' => $point[1], 'id' => null]];
            }
        }
    }
    echo json_encode($visits);

    return;
}

// tracks: one per weekday, the id is the date (Ymd); newest first and paginated like Tracks::IndexQuery
if ($path === '/api/v1/tracks') {
    $from = strtotime($_GET['start_at']);
    $to = strtotime($_GET['end_at']);
    $features = [];
    foreach (weekdays($from - 86400, $to) as $d) {
        $track = track($d);
        if ($track['end'] >= $from && $track['start'] <= $to) {
            $features[] = feature((int) $d->format('Ymd'), $track, false);
        }
    }
    $features = array_reverse($features);
    $per = max(1, (int) ($_GET['per_page'] ?? 500));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    header('X-Current-Page: ' . $page);
    header('X-Total-Pages: ' . (int) ceil(count($features) / $per));
    header('X-Total-Count: ' . count($features));
    echo json_encode(['type' => 'FeatureCollection', 'features' => array_slice($features, ($page - 1) * $per, $per)]);

    return;
}
if (preg_match('#^/api/v1/tracks/(\d{8})$#', $path, $m)) {
    $d = new DateTimeImmutable($m[1], new DateTimeZone(ZONE));
    echo json_encode(['type' => 'FeatureCollection', 'features' => [feature((int) $m[1], track($d), true)]]);

    return;
}

http_response_code(404);
echo '{"error":"not found"}';
