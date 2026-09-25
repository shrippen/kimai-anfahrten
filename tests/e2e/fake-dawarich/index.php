<?php

/*
 * Minimal fake of the Dawarich API for end-to-end tests: areas, places, reverse geocoding (places/nearby) and tracks
 * with transportation-mode segments, in the shape of Dawarich's API controllers and serializers (Dawarich 1.15.2).
 * Every weekday is one track (the phone records all day, Dawarich only starts a new track after a 30-minute gap):
 * home → office (car), a walk to lunch and back, office → customer → home (car), and an evening bike ride, with
 * standstills in between.
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

// places (Api::V1::PlacesController#serialize_place)
if ($path === '/api/v1/places') {
    echo json_encode([
        ['id' => 11, 'name' => 'Kunde Potsdam', 'latitude' => CUSTOMER[0], 'longitude' => CUSTOMER[1], 'source' => 'manual', 'note' => null,
            'icon' => null, 'color' => null, 'visits_count' => 3, 'name_locked' => true, 'created_at' => '2026-01-01T00:00:00Z', 'tags' => []],
    ]);

    return;
}

// reverse geocoding (Places::NearbySearch → Places::PhotonResultFormatter); empty without a geocoder
if ($path === '/api/v1/places/nearby') {
    $at = [(float) $_GET['latitude'], (float) $_GET['longitude']];
    $addresses = [
        [HOME, 'Wohnhaus', 'Alexanderstraße', '7', '10178', 'Berlin'],
        [OFFICE, 'Bürohaus', 'Hardenbergstraße', '32', '10623', 'Berlin'],
        [CUSTOMER, 'ACME GmbH', 'Kundenweg', '1', '14467', 'Potsdam'],
    ];
    $radius = 1000 * (float) ($_GET['radius'] ?? 0.5);
    $places = [];
    foreach ($addresses as [$point, $name, $street, $number, $postcode, $city]) {
        if (metres($point, $at) <= $radius) {
            $places[] = ['id' => null, 'name' => $name, 'latitude' => $point[0], 'longitude' => $point[1], 'osm_id' => null, 'osm_type' => null,
                'osm_key' => null, 'osm_value' => null, 'city' => $city, 'country' => 'Germany', 'street' => $street, 'housenumber' => $number,
                'postcode' => $postcode, 'source' => 'photon', 'geodata' => []];
        }
    }
    echo json_encode(['places' => array_slice($places, 0, (int) ($_GET['limit'] ?? 10))]);

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
function day(DateTimeImmutable $d): array
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

    $add(HOME, HOME, '06:00', '07:30', 'stationary');
    $add(HOME, OFFICE, '07:30', '07:50', 'driving');
    $add(OFFICE, OFFICE, '07:50', '10:00', 'stationary');
    $add(OFFICE, CAFE, '10:00', '10:25', 'walking');
    $add(CAFE, CAFE, '10:25', '10:40', 'stationary');
    $add(CAFE, OFFICE, '10:40', '11:05', 'walking');
    $add(OFFICE, OFFICE, '11:05', '12:00', 'stationary');
    $add(OFFICE, CUSTOMER, '12:00', '12:35', 'driving');
    $add(CUSTOMER, CUSTOMER, '12:35', '16:00', 'stationary');
    $add(CUSTOMER, HOME, '16:00', '16:45', 'driving');
    $add(HOME, HOME, '16:45', '18:00', 'stationary');
    $add(HOME, LAKE, '18:00', '18:40', 'cycling');
    $add(LAKE, LAKE, '18:40', '19:10', 'stationary');
    $add(LAKE, HOME, '19:10', '19:50', 'cycling');
    $add(HOME, HOME, '19:50', '22:00', 'stationary');

    $coordinates = [];
    foreach ($segments as $segment) {
        array_push($coordinates, ...$segment['coordinates']);
    }

    return ['start' => $t('06:00'), 'end' => $t('22:00'), 'segments' => $segments, 'coordinates' => $coordinates];
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
    $tz = new DateTimeZone('Europe/Berlin');
    for ($d = (new DateTimeImmutable('@' . $from))->setTimezone($tz)->setTime(0, 0); $d->getTimestamp() < $to; $d = $d->modify('+1 day')) {
        if ((int) $d->format('N') <= 5) {
            yield $d;
        }
    }
}

// tracks: one per weekday, the id is the date (Ymd); newest first and paginated like Tracks::IndexQuery
if ($path === '/api/v1/tracks') {
    $from = strtotime($_GET['start_at']);
    $to = strtotime($_GET['end_at']);
    $features = [];
    foreach (weekdays($from - 86400, $to) as $d) {
        $day = day($d);
        if ($day['end'] >= $from && $day['start'] <= $to) {
            $features[] = feature((int) $d->format('Ymd'), $day, false);
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
    $d = new DateTimeImmutable($m[1], new DateTimeZone('Europe/Berlin'));
    echo json_encode(['type' => 'FeatureCollection', 'features' => [feature((int) $m[1], day($d), true)]]);

    return;
}

http_response_code(404);
echo '{"error":"not found"}';
